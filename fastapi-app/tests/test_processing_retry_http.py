from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.parsing.checkpoint import ParseCheckpointStore
from app.parsing.providers.llamaparse import LlamaParsePage
from app.processing.base import ProcessingProfile
from app.processing.registry import ProcessingProfileRegistry
from app.schemas.documents import DocumentFileType
from app.services.document_processing import ProcessDocumentService
from test_document_processing_service import (
    FakeIndexer,
    FakeLoader,
    FakeProgressNotifier,
    build_profile,
)
from test_process_document_api import TEST_API_KEY, create_test_client, valid_form_data


def test_http_retry_after_embedding_failure_reuses_persisted_parse(tmp_path):
    events = []
    profile, _, embedder, _ = build_profile(ProcessingProfile.CLOUD, events)
    original = embedder.embed
    attempts = 0

    def fail_once(chunks):
        nonlocal attempts
        attempts += 1
        if attempts == 1:
            raise ApplicationException(code="dense_embedding_failed", message="Injected failure")
        return original(chunks)

    embedder.embed = fail_once

    def service():
        return ProcessDocumentService(settings=Settings(_env_file=None),
            loaders={DocumentFileType.PDF: FakeLoader(events)},
            profile_registry=ProcessingProfileRegistry([profile]),
            indexer=FakeIndexer(events=events), progress_notifier=FakeProgressNotifier(events=events),
            parse_checkpoints=ParseCheckpointStore(tmp_path / "checkpoints"))

    arguments = {"headers": {"X-Internal-API-Key": TEST_API_KEY},
                 "data": valid_form_data(), "files": {"file": ("a.pdf", b"same bytes", "application/pdf")}}
    first = create_test_client(service()).post("/api/v1/documents/process", **arguments)
    assert first.status_code == 500
    assert first.json()["error"]["code"] == "dense_embedding_failed"
    # Recreate the service to prove the checkpoint does not depend on process memory.
    second = create_test_client(service()).post("/api/v1/documents/process", **arguments)
    assert second.status_code == 200
    assert second.json()["status"] == "indexed"
    assert events.count("parse") == 1
    assert attempts == 2


def test_http_retry_after_polling_interruption_resumes_existing_parse_job(tmp_path):
    events = []
    profile, _, _, _ = build_profile(ProcessingProfile.CLOUD, events)

    class ResumableLoader:
        def __init__(self):
            self.submissions = []
            self.resumptions = []

        def load(self, file_path):
            raise AssertionError("resumable loaders must not use one-shot load")

        def submit(self, file_path):
            self.submissions.append(file_path.read_bytes())
            return "job-123"

        def resume(self, job_id):
            self.resumptions.append(job_id)
            if len(self.resumptions) == 1:
                raise TimeoutError("temporary polling interruption")
            return [LlamaParsePage(1, "Test document content.")]

    loader = ResumableLoader()

    def service():
        return ProcessDocumentService(
            settings=Settings(_env_file=None),
            loaders={DocumentFileType.PDF: loader},
            profile_registry=ProcessingProfileRegistry([profile]),
            indexer=FakeIndexer(events=events),
            progress_notifier=FakeProgressNotifier(events=events),
            parse_checkpoints=ParseCheckpointStore(tmp_path / "checkpoints"),
        )

    arguments = {
        "headers": {"X-Internal-API-Key": TEST_API_KEY},
        "data": valid_form_data(),
        "files": {"file": ("a.pdf", b"same bytes", "application/pdf")},
    }

    first = create_test_client(service()).post(
        "/api/v1/documents/process", **arguments
    )
    assert first.status_code == 500
    assert first.json()["error"]["code"] == "document_parsing_failed"

    second = create_test_client(service()).post(
        "/api/v1/documents/process", **arguments
    )

    assert second.status_code == 200
    assert second.json()["status"] == "indexed"
    assert loader.submissions == [b"same bytes"]
    assert loader.resumptions == ["job-123", "job-123"]
