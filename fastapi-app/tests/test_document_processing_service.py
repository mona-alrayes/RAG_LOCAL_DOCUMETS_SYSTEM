from pathlib import Path

import pytest
from qdrant_client import models

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.indexer import IndexingResult
from app.parsing.base import BaseDocumentLoader
from app.parsing.providers.llamaparse import LlamaParsePage
from app.processing.base import ProcessingProfile
from app.processing.chunks import NormalizedChunk
from app.processing.profiles import ExecutableProcessingProfile
from app.processing.registry import ProcessingProfileRegistry
from app.schemas.documents import (
    DocumentFileType,
    ProcessDocumentRequest,
)
from app.services import document_processing as document_processing_module
from app.services.document_processing import ProcessDocumentService


class FakeLoader(BaseDocumentLoader[LlamaParsePage]):
    def __init__(self, events: list[str] | None = None) -> None:
        self.events = events

    def load(self, file_path: Path) -> list[LlamaParsePage]:
        if self.events is not None:
            self.events.append("parse")

        return [
            LlamaParsePage(
                page_number=1,
                markdown="Test document content.",
            )
        ]


class FakeChunker:
    def __init__(self, events: list[str] | None = None) -> None:
        self.called = False
        self.events = events

    def chunk(self, documents):
        self.called = True

        if self.events is not None:
            self.events.append("chunk")

        return [
            NormalizedChunk(
                text=documents[0].text,
                page=documents[0].page,
                section=None,
            )
        ]


class FakeDenseEmbedder:
    def __init__(self, events: list[str] | None = None) -> None:
        self.called = False
        self.events = events

    def embed(self, chunks):
        self.called = True

        if self.events is not None:
            self.events.append("dense_embedding")

        return [[0.0] * 1024 for _ in chunks]


class FakeSparseRepresenter:
    def __init__(self, events: list[str] | None = None) -> None:
        self.called = False
        self.events = events

    def represent(self, chunks):
        self.called = True

        if self.events is not None:
            self.events.append("sparse_representation")

        return [
            models.SparseVector(
                indices=[1],
                values=[1.0],
            )
            for _ in chunks
        ]


class FakeIndexer:
    def __init__(
        self,
        *,
        persisted_count: int | None = None,
        failure: ApplicationException | None = None,
        events: list[str] | None = None,
    ) -> None:
        self.persisted_count = persisted_count
        self.failure = failure
        self.context = None
        self.called = False
        self.events = events

    def index(
        self,
        *,
        context,
        chunks,
        dense_vectors,
        sparse_representations,
    ) -> IndexingResult:
        self.called = True
        self.context = context

        if self.events is not None:
            self.events.append("qdrant_write")

        if self.failure is not None:
            raise self.failure

        vector_count = (
            len(chunks)
            if self.persisted_count is None
            else self.persisted_count
        )

        return IndexingResult(
            collection_name=context.collection_name,
            vector_count=vector_count,
        )


class FakeProgressNotifier:
    def __init__(
        self,
        *,
        events: list[str] | None = None,
        failure: ApplicationException | None = None,
    ) -> None:
        self.events = events
        self.failure = failure
        self.requests: list[ProcessDocumentRequest] = []

    def notify_indexing_started(
        self,
        *,
        request: ProcessDocumentRequest,
        correlation_id: str | None,
    ) -> None:
        self.requests.append(request)

        if self.events is not None:
            self.events.append("indexing_started_callback")

        if self.failure is not None:
            raise self.failure


def build_profile(
    profile: ProcessingProfile,
    events: list[str] | None = None,
):
    chunker = FakeChunker(events)
    dense_embedder = FakeDenseEmbedder(events)
    sparse_representer = FakeSparseRepresenter(events)

    executable_profile = ExecutableProcessingProfile(
        profile=profile,
        chunker=chunker,
        dense_embedder=dense_embedder,
        sparse_representer_factory=lambda: sparse_representer,
    )

    return (
        executable_profile,
        chunker,
        dense_embedder,
        sparse_representer,
    )


def build_request(
    *,
    profile: ProcessingProfile,
    file_type: DocumentFileType = DocumentFileType.PDF,
) -> ProcessDocumentRequest:
    return ProcessDocumentRequest(
        user_id=10,
        document_id=20,
        processing_run_id=30,
        processing_profile=profile,
        file_type=file_type,
    )


@pytest.mark.parametrize("blocked", [True, False])
def test_memory_preflight_precedes_external_parse(blocked):
    events = []
    profile, _, _, _ = build_profile(ProcessingProfile.HYBRID_LOCAL, events)

    def preflight():
        events.append("memory_check")
        if blocked:
            raise ApplicationException(code="local_resource_exhausted", message="Low memory")

    profile._preflight = preflight
    service = ProcessDocumentService(
        settings=Settings(_env_file=None),
        loaders={DocumentFileType.PDF: FakeLoader(events)},
        profile_registry=ProcessingProfileRegistry([profile]),
        indexer=FakeIndexer(events=events),
        progress_notifier=FakeProgressNotifier(events=events),
    )
    arguments = {"request": build_request(profile=ProcessingProfile.HYBRID_LOCAL),
                 "file_path": Path("test.pdf"), "source": "test.pdf"}
    if blocked:
        with pytest.raises(ApplicationException) as error:
            service.process(**arguments)
        assert error.value.code == "local_resource_exhausted"
        assert events == ["memory_check"]
    else:
        service.process(**arguments)
        assert events[:2] == ["memory_check", "parse"]


def test_cloud_processing_returns_indexed_response_after_indexing(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    settings = Settings(_env_file=None)
    events: list[str] = []
    normalize = document_processing_module.normalize_llamaparse_pages

    def normalize_with_event(*args, **kwargs):
        events.append("normalize")
        return normalize(*args, **kwargs)

    monkeypatch.setattr(
        document_processing_module,
        "normalize_llamaparse_pages",
        normalize_with_event,
    )

    (
        cloud_profile,
        chunker,
        dense_embedder,
        sparse_representer,
    ) = build_profile(ProcessingProfile.CLOUD, events)

    indexer = FakeIndexer(events=events)
    progress_notifier = FakeProgressNotifier(events=events)

    service = ProcessDocumentService(
        settings=settings,
        loaders={
            DocumentFileType.PDF: FakeLoader(events),
        },
        profile_registry=ProcessingProfileRegistry(
            [cloud_profile]
        ),
        indexer=indexer,  # type: ignore[arg-type]
        progress_notifier=progress_notifier,
    )

    response = service.process(
        request=build_request(
            profile=ProcessingProfile.CLOUD,
        ),
        file_path=Path("/temporary/document.pdf"),
        source="document.pdf",
    )

    assert response.status == "indexed"
    assert response.profile is ProcessingProfile.CLOUD
    assert response.qdrant_collection == settings.qdrant_cloud_collection
    assert response.total_pages == 1
    assert response.total_chunks == 1
    assert response.vector_count == 1
    assert response.vector_dimension == 1024

    assert chunker.called is True
    assert dense_embedder.called is True
    assert sparse_representer.called is True

    assert indexer.context is not None
    assert indexer.context.user_id == 10
    assert indexer.context.document_id == 20
    assert indexer.context.processing_run_id == 30
    assert indexer.context.processing_profile == "cloud"
    assert indexer.context.file_type == "pdf"
    assert indexer.context.source == "document.pdf"
    assert progress_notifier.requests == [
        build_request(profile=ProcessingProfile.CLOUD)
    ]
    assert events == [
        "parse",
        "normalize",
        "chunk",
        "dense_embedding",
        "sparse_representation",
        "indexing_started_callback",
        "qdrant_write",
    ]


def test_hybrid_local_processing_uses_only_selected_profile() -> None:
    settings = Settings(_env_file=None)

    (
        cloud_profile,
        cloud_chunker,
        cloud_dense,
        cloud_sparse,
    ) = build_profile(ProcessingProfile.CLOUD)

    (
        local_profile,
        local_chunker,
        local_dense,
        local_sparse,
    ) = build_profile(ProcessingProfile.HYBRID_LOCAL)

    progress_notifier = FakeProgressNotifier()

    service = ProcessDocumentService(
        settings=settings,
        loaders={
            DocumentFileType.DOCX: FakeLoader(),
        },
        profile_registry=ProcessingProfileRegistry(
            [
                cloud_profile,
                local_profile,
            ]
        ),
        indexer=FakeIndexer(),  # type: ignore[arg-type]
        progress_notifier=progress_notifier,
    )

    response = service.process(
        request=build_request(
            profile=ProcessingProfile.HYBRID_LOCAL,
            file_type=DocumentFileType.DOCX,
        ),
        file_path=Path("/temporary/document.docx"),
        source="document.docx",
    )

    assert response.status == "indexed"
    assert response.profile is ProcessingProfile.HYBRID_LOCAL
    assert (
        response.qdrant_collection
        == settings.qdrant_hybrid_local_collection
    )

    # DOCX must not expose unreliable physical page numbers.
    assert response.total_pages is None

    assert local_chunker.called is True
    assert local_dense.called is True
    assert local_sparse.called is True

    assert cloud_chunker.called is False
    assert cloud_dense.called is False
    assert cloud_sparse.called is False
    assert len(progress_notifier.requests) == 1
    assert (
        progress_notifier.requests[0].processing_profile
        is ProcessingProfile.HYBRID_LOCAL
    )


def test_indexing_failure_never_returns_indexed_response() -> None:
    settings = Settings(_env_file=None)

    cloud_profile, _, _, _ = build_profile(
        ProcessingProfile.CLOUD
    )

    indexer = FakeIndexer(
        failure=ApplicationException(
            code="qdrant_index_count_mismatch",
            message=(
                "Persisted Qdrant point count does not match "
                "the processing run chunk count."
            ),
        )
    )

    service = ProcessDocumentService(
        settings=settings,
        loaders={
            DocumentFileType.PDF: FakeLoader(),
        },
        profile_registry=ProcessingProfileRegistry(
            [cloud_profile]
        ),
        indexer=indexer,  # type: ignore[arg-type]
        progress_notifier=FakeProgressNotifier(),
    )

    with pytest.raises(ApplicationException) as exc_info:
        service.process(
            request=build_request(
                profile=ProcessingProfile.CLOUD,
            ),
            file_path=Path("/temporary/document.pdf"),
            source="document.pdf",
        )

    assert exc_info.value.code == "qdrant_index_count_mismatch"


def test_callback_final_failure_prevents_qdrant_write() -> None:
    settings = Settings(_env_file=None)
    cloud_profile, _, _, _ = build_profile(ProcessingProfile.CLOUD)
    indexer = FakeIndexer()
    progress_notifier = FakeProgressNotifier(
        failure=ApplicationException(
            code="processing_progress_callback_failed",
            message="Processing progress callback failed.",
        )
    )

    service = ProcessDocumentService(
        settings=settings,
        loaders={DocumentFileType.PDF: FakeLoader()},
        profile_registry=ProcessingProfileRegistry([cloud_profile]),
        indexer=indexer,  # type: ignore[arg-type]
        progress_notifier=progress_notifier,
    )

    with pytest.raises(ApplicationException) as exc_info:
        service.process(
            request=build_request(profile=ProcessingProfile.CLOUD),
            file_path=Path("/temporary/document.pdf"),
            source="document.pdf",
        )

    assert exc_info.value.code == "processing_progress_callback_failed"
    assert indexer.called is False
