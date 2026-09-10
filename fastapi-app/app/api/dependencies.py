from collections.abc import Iterator

from app.core.config import DeploymentMode, Settings, get_settings
from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.client import build_qdrant_client
from app.infrastructure.qdrant.indexer import QdrantDocumentIndexer
from app.parsing.base import BaseDocumentLoader
from app.parsing.docx import DocxDocumentLoader
from app.parsing.pdf import PdfDocumentLoader
from app.parsing.providers.llamaparse import (
    LlamaParsePage,
    LlamaParseProvider,
)
from app.parsing.txt import TxtDocumentLoader
from app.processing.base import ProcessingProfile
from app.processing.cloud_chunking import CloudChunker
from app.processing.cloud_embeddings import CloudJinaEmbedder
from app.processing.cloud_sparse import CloudSparseRepresenter
from app.processing.hybrid_local_chunking import HybridLocalChunker
from app.processing.local_embeddings import LocalBgeM3Embedder
from app.processing.local_sparse import LocalBm25Representer
from app.processing.profiles import ExecutableProcessingProfile
from app.processing.registry import ProcessingProfileRegistry
from app.runtime.state import (
    local_model_coordinator_state,
    local_runtime_state,
)
from app.schemas.documents import DocumentFileType
from app.services.document_processing import ProcessDocumentService
from app.services.processing_progress import LaravelProcessingProgressClient
from app.services.processing_run_cleanup import (
    ProcessingRunPointsCleanupService,
)


def get_process_document_service() -> Iterator[ProcessDocumentService]:
    settings = get_settings()
    qdrant_client = build_qdrant_client(settings)

    try:
        yield ProcessDocumentService(
            settings=settings,
            loaders=_build_loaders(settings),
            profile_registry=_build_profile_registry(settings),
            indexer=QdrantDocumentIndexer(qdrant_client),
            progress_notifier=LaravelProcessingProgressClient.from_settings(
                settings
            ),
        )
    finally:
        qdrant_client.close()


def get_processing_run_points_cleanup_service(
) -> Iterator[ProcessingRunPointsCleanupService]:
    settings = get_settings()
    qdrant_client = build_qdrant_client(settings)

    try:
        yield ProcessingRunPointsCleanupService(
            settings=settings,
            client=qdrant_client,
        )
    finally:
        qdrant_client.close()


def _build_loaders(
    settings: Settings,
) -> dict[
    DocumentFileType,
    BaseDocumentLoader[LlamaParsePage],
]:
    api_key = settings.llama_cloud_api_key

    if (
        api_key is None
        or not api_key.get_secret_value().strip()
    ):
        raise ApplicationException(
            code="document_parser_not_configured",
            message="Document parsing provider is not configured.",
        )

    provider = LlamaParseProvider(
        api_key=api_key.get_secret_value(),
    )

    return {
        DocumentFileType.PDF: PdfDocumentLoader(provider),
        DocumentFileType.DOCX: DocxDocumentLoader(provider),
        DocumentFileType.TXT: TxtDocumentLoader(provider),
    }


def _build_profile_registry(
    settings: Settings,
) -> ProcessingProfileRegistry[
    ExecutableProcessingProfile
]:
    profiles = [
        _build_cloud_profile(settings),
    ]

    local_profile = _build_local_profile(settings)

    if local_profile is not None:
        profiles.append(local_profile)

    return ProcessingProfileRegistry(profiles)


def _build_cloud_profile(
    settings: Settings,
) -> ExecutableProcessingProfile:
    return ExecutableProcessingProfile(
        profile=ProcessingProfile.CLOUD,
        chunker=CloudChunker(
            chunk_size=settings.chunk_size,
            chunk_overlap=settings.chunk_overlap,
        ),
        dense_embedder=CloudJinaEmbedder(
            api_key=settings.jinaai_api_key.get_secret_value(),
            model=settings.cloud_embed_model,
            batch_size=settings.embed_batch_size,
            wait_between_batches=settings.wait_between_batches,
            rate_limit_retry_wait=settings.rate_limit_retry_wait,
            max_retries=settings.max_retries,
        ),
        sparse_representer_factory=CloudSparseRepresenter,
    )


def _build_local_profile(
    settings: Settings,
) -> ExecutableProcessingProfile | None:
    if settings.rag_deployment_mode is not DeploymentMode.LOCAL:
        return None

    runtime = local_runtime_state.get()
    coordinator = local_model_coordinator_state.get()

    if (
        runtime is None
        or coordinator is None
        or not runtime.ready
        or runtime.selected_backend is None
        or runtime.selected_dtype is None
    ):
        return None

    return ExecutableProcessingProfile(
        profile=ProcessingProfile.HYBRID_LOCAL,
        chunker=HybridLocalChunker(
            chunk_size=settings.chunk_size,
            chunk_overlap=settings.chunk_overlap,
        ),
        dense_embedder=LocalBgeM3Embedder(
            model=settings.local_embed_model,
            runtime=runtime,
            coordinator=coordinator,
        ),
        sparse_representer_factory=LocalBm25Representer,
    )
