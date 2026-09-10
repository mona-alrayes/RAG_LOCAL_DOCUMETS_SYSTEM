from collections.abc import Mapping
from pathlib import Path
from time import perf_counter

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.core.logging import get_correlation_id
from app.infrastructure.qdrant.indexer import (
    IndexingContext,
    QdrantDocumentIndexer,
)
from app.parsing.base import BaseDocumentLoader
from app.parsing.normalization import normalize_llamaparse_pages
from app.parsing.providers.llamaparse import LlamaParsePage
from app.processing.indexing import resolve_qdrant_collection
from app.processing.profiles import ExecutableProcessingProfile
from app.processing.registry import ProcessingProfileRegistry
from app.processing.reporting import (
    ProcessingReportBuilder,
    ProcessingStage,
    build_profile_snapshot,
)
from app.schemas.documents import (
    DocumentFileType,
    ProcessDocumentRequest,
    ProcessDocumentResponse,
)
from app.services.processing_progress import ProcessingProgressNotifier


class ProcessDocumentService:
    def __init__(
        self,
        *,
        settings: Settings,
        loaders: Mapping[
            DocumentFileType,
            BaseDocumentLoader[LlamaParsePage],
        ],
        profile_registry: ProcessingProfileRegistry[
            ExecutableProcessingProfile
        ],
        indexer: QdrantDocumentIndexer,
        progress_notifier: ProcessingProgressNotifier,
        report_builder: ProcessingReportBuilder | None = None,
    ) -> None:
        self._settings = settings
        self._loaders = loaders
        self._profile_registry = profile_registry
        self._indexer = indexer
        self._progress_notifier = progress_notifier
        self._report_builder = report_builder or ProcessingReportBuilder()

    def process(
        self,
        *,
        request: ProcessDocumentRequest,
        file_path: Path,
        source: str,
    ) -> ProcessDocumentResponse:
        total_started_at = perf_counter()

        profile = self._profile_registry.resolve(
            request.processing_profile
        )
        loader = self._resolve_loader(request.file_type)

        documents, parse_ms = self._parse_document(
            loader=loader,
            file_path=file_path,
            file_type=request.file_type,
        )

        chunks, chunk_ms = self._chunk_document(
            profile=profile,
            documents=documents,
        )

        dense_vectors, dense_ms = self._embed_chunks(
            profile=profile,
            chunks=chunks,
        )

        sparse_representations, sparse_ms = (
            self._represent_sparse(
                profile=profile,
                chunks=chunks,
            )
        )

        collection_name = resolve_qdrant_collection(
            profile=request.processing_profile,
            settings=self._settings,
        )

        self._progress_notifier.notify_indexing_started(
            request=request,
            correlation_id=get_correlation_id(),
        )

        indexing_result = self._index_document(
            request=request,
            source=source,
            collection_name=collection_name,
            chunks=chunks,
            dense_vectors=dense_vectors,
            sparse_representations=sparse_representations,
        )

        total_ms = self._elapsed_ms(total_started_at)

        profile_snapshot = build_profile_snapshot(
            profile=request.processing_profile,
            settings=self._settings,
        )

        report = self._report_builder.build(
            profile_snapshot=profile_snapshot,
            documents=documents,
            chunks=chunks,
            dense_vectors=dense_vectors,
            stage_timings_ms={
                ProcessingStage.PARSE: parse_ms,
                ProcessingStage.CHUNK: chunk_ms,
                ProcessingStage.DENSE_EMBEDDING: dense_ms,
                ProcessingStage.SPARSE_REPRESENTATION: sparse_ms,
                ProcessingStage.TOTAL: total_ms,
            },
        )

        return ProcessDocumentResponse(
            document_id=request.document_id,
            processing_run_id=request.processing_run_id,
            profile=request.processing_profile,
            status="indexed",
            qdrant_collection=indexing_result.collection_name,
            profile_snapshot=report.profile_snapshot,
            total_pages=report.total_pages,
            total_chunks=report.total_chunks,
            vector_count=indexing_result.vector_count,
            vector_dimension=report.vector_dimension,
            stage_timings_ms=report.stage_timings_ms,
            warnings=report.warnings,
        )

    def _resolve_loader(
        self,
        file_type: DocumentFileType,
    ) -> BaseDocumentLoader[LlamaParsePage]:
        try:
            return self._loaders[file_type]
        except KeyError:
            raise ApplicationException(
                code="document_loader_not_registered",
                message="Document file type has no registered loader.",
            ) from None

    def _parse_document(
        self,
        *,
        loader: BaseDocumentLoader[LlamaParsePage],
        file_path: Path,
        file_type: DocumentFileType,
    ):
        started_at = perf_counter()

        try:
            pages = loader.load(file_path)

            documents = normalize_llamaparse_pages(
                pages,
                preserve_page_numbers=(
                    file_type is DocumentFileType.PDF
                ),
            )
        except ApplicationException:
            raise
        except Exception as exc:
            raise ApplicationException(
                code="document_parsing_failed",
                message="Document parsing failed.",
            ) from exc

        return documents, self._elapsed_ms(started_at)

    def _chunk_document(
        self,
        *,
        profile: ExecutableProcessingProfile,
        documents,
    ):
        started_at = perf_counter()

        try:
            chunks = profile.chunk(documents)
        except ApplicationException:
            raise
        except Exception as exc:
            raise ApplicationException(
                code="document_chunking_failed",
                message="Document chunking failed.",
            ) from exc

        return chunks, self._elapsed_ms(started_at)

    def _embed_chunks(
        self,
        *,
        profile: ExecutableProcessingProfile,
        chunks,
    ):
        started_at = perf_counter()

        try:
            vectors = profile.embed(chunks)
        except ApplicationException:
            raise
        except Exception as exc:
            raise ApplicationException(
                code="dense_embedding_failed",
                message="Dense embedding failed.",
            ) from exc

        return vectors, self._elapsed_ms(started_at)

    def _represent_sparse(
        self,
        *,
        profile: ExecutableProcessingProfile,
        chunks,
    ):
        started_at = perf_counter()

        try:
            representations = profile.represent_sparse(chunks)
        except ApplicationException:
            raise
        except Exception as exc:
            raise ApplicationException(
                code="sparse_representation_failed",
                message="Sparse representation failed.",
            ) from exc

        return representations, self._elapsed_ms(started_at)

    def _index_document(
        self,
        *,
        request: ProcessDocumentRequest,
        source: str,
        collection_name: str,
        chunks,
        dense_vectors,
        sparse_representations,
    ):
        context = IndexingContext(
            user_id=request.user_id,
            document_id=request.document_id,
            processing_run_id=request.processing_run_id,
            processing_profile=request.processing_profile.value,
            file_type=request.file_type.value,
            source=source,
            collection_name=collection_name,
        )

        try:
            return self._indexer.index(
                context=context,
                chunks=chunks,
                dense_vectors=dense_vectors,
                sparse_representations=sparse_representations,
            )
        except ApplicationException:
            raise
        except Exception as exc:
            raise ApplicationException(
                code="qdrant_indexing_failed",
                message="Document indexing failed.",
            ) from exc

    @staticmethod
    def _elapsed_ms(started_at: float) -> int:
        return max(
            0,
            int((perf_counter() - started_at) * 1000),
        )
