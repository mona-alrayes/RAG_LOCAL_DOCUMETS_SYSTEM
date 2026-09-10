"""Reject duplicate local runtimes; thread gates alone cannot coordinate processes."""

import hashlib
import os
import tempfile
from pathlib import Path

from app.core.exceptions import ApplicationException


class LocalProcessGuard:
    def __init__(self, path: str | Path | None = None):
        # Resolve the project path so --reload and a second shell share the lock.
        project = hashlib.sha256(str(Path.cwd().resolve()).encode()).hexdigest()[:16]
        self.path = (
            Path(path)
            if path is not None
            else Path(tempfile.gettempdir()) / f"rag-runtime-{project}.lock"
        )
        self.handle = None

    def __enter__(self):
        descriptor = os.open(
            self.path, os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0), 0o600
        )
        self.handle = os.fdopen(descriptor, "r+b")
        try:
            if os.name == "nt":
                import msvcrt

                self.handle.write(b"0")
                self.handle.flush()
                self.handle.seek(0)
                msvcrt.locking(self.handle.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl

                fcntl.flock(self.handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            self.handle.close()
            self.handle = None
            raise ApplicationException(
                code="local_runtime_already_running",
                message="A local AI runtime is already running for this project. Use one worker.",
            ) from None
        return self

    def __exit__(self, *_):
        if self.handle is not None:
            self.handle.close()
            self.handle = None
        # Keep the inode: unlinking a lock file would allow two independent locks.
