"""Private durable parsing checkpoints, shared by retries of the same document."""

import hashlib
import json
import os
import tempfile

if os.name == "nt":
    import msvcrt
else:
    import fcntl
from contextlib import contextmanager
from dataclasses import asdict
from pathlib import Path

from app.core.exceptions import ApplicationException
from app.parsing.providers.llamaparse import LlamaParsePage


class ParseCheckpointStore:
    def __init__(self, root: Path):
        self.root = root

    @contextmanager
    def _locked(self, user_id: int, document_id: int):
        if user_id <= 0 or document_id <= 0:
            raise ValueError("Invalid checkpoint scope")

        self.root.mkdir(parents=True, exist_ok=True, mode=0o700)
        directory = self.root / f"{user_id}-{document_id}"
        directory.mkdir(exist_ok=True, mode=0o700)

        descriptor = os.open(
            directory / ".lock",
            os.O_CREAT | os.O_RDWR,
            0o600,
        )

        with os.fdopen(descriptor, "a+b") as lock:
            if os.name == "nt":
                lock.seek(0, os.SEEK_END)
                if lock.tell() == 0:
                    lock.write(b"\0")
                    lock.flush()

                lock.seek(0)
                msvcrt.locking(
                    lock.fileno(),
                    msvcrt.LK_LOCK,
                    1,
                )
            else:
                fcntl.flock(
                    lock.fileno(),
                    fcntl.LOCK_EX,
                )

            try:
                yield directory
            finally:
                if os.name == "nt":
                    lock.seek(0)
                    msvcrt.locking(
                        lock.fileno(),
                        msvcrt.LK_UNLCK,
                        1,
                    )
                else:
                    fcntl.flock(
                        lock.fileno(),
                        fcntl.LOCK_UN,
                    )

    @staticmethod
    def _write(path: Path, data: dict):
        temporary = None
        try:
            with tempfile.NamedTemporaryFile(
                mode="w",
                encoding="utf-8",
                dir=path.parent,
                delete=False,
            ) as output:
                temporary = Path(output.name)
                json.dump(data, output, ensure_ascii=False)
                output.flush()
                os.fsync(output.fileno())
            os.replace(temporary, path)
            if os.name != "nt":
                descriptor = os.open(path.parent, os.O_RDONLY)
                try:
                    os.fsync(descriptor)
                finally:
                    os.close(descriptor)
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)

    @staticmethod
    def _read(path: Path):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            assert data["state"] in {"pending", "complete"}
            assert isinstance(data["runs"], list)
            if data["state"] == "complete":
                assert isinstance(data["pages"], list) and data["pages"]
                for page in data["pages"]:
                    assert type(page["page_number"]) is int
                    assert isinstance(page["markdown"], str)
            return data
        except (ValueError, KeyError, TypeError, AssertionError) as exc:
            raise ApplicationException(
                code="document_parsing_checkpoint_invalid",
                message="Saved parsing state is invalid; automatic resubmission stopped.",
            ) from exc

    def load(
        self,
        *,
        user_id,
        document_id,
        run_id,
        file_path,
        parser_signature,
        loader=None,
        submitter=None,
        resumer=None,
    ):
        resumable = submitter is not None or resumer is not None
        if resumable and (submitter is None or resumer is None):
            raise ValueError("Resumable parsing requires submitter and resumer")
        if not resumable and loader is None:
            raise ValueError("Parsing requires a loader or resumable callbacks")

        with file_path.open("rb") as source:
            file_hash = hashlib.file_digest(source, "sha256").hexdigest()
        key = hashlib.sha256(
            f"{file_hash}:{parser_signature}:{file_path.suffix}".encode()
        ).hexdigest()
        with self._locked(user_id, document_id) as directory:
            path = directory / f"{key}.json"
            if path.exists():
                data = self._read(path)
                if run_id not in data["runs"]:
                    data["runs"].append(run_id)
                    self._write(path, data)
                if data["state"] == "complete":
                    return [
                        LlamaParsePage(**page) for page in data["pages"]
                    ]

                job_id = data.get("job_id")
                if not resumable or not isinstance(job_id, str) or not job_id:
                    raise ApplicationException(
                        code="document_parsing_outcome_unknown",
                        message="Previous parsing outcome is unknown; check the provider before resubmitting.",
                    )

                pages = resumer(job_id)
                if not pages:
                    raise ApplicationException(
                        code="document_parsing_empty",
                        message="The parser returned no pages.",
                    )
                data.update(
                    state="complete",
                    pages=[asdict(page) for page in pages],
                )
                self._write(path, data)
                return pages

            # Persist intent before any external call. A crash must never silently resubmit.
            data = {"state": "pending", "runs": [run_id]}
            self._write(path, data)
            if resumable:
                job_id = submitter()
                if not isinstance(job_id, str) or not job_id:
                    raise ValueError("Parser returned an invalid job ID")
                data["job_id"] = job_id
                self._write(path, data)
                pages = resumer(job_id)
            else:
                pages = loader()
            if not pages:
                raise ApplicationException(
                    code="document_parsing_empty",
                    message="The parser returned no pages.",
                )
            data.update(state="complete", pages=[asdict(page) for page in pages])
            self._write(path, data)
            return pages

    def delete_run(self, *, user_id, document_id, run_id):
        with self._locked(user_id, document_id) as directory:
            for path in directory.glob("*.json"):
                data = self._read(path)
                if run_id not in data["runs"]:
                    continue
                data["runs"].remove(run_id)
                if data["runs"]:
                    self._write(path, data)
                else:
                    path.unlink()
