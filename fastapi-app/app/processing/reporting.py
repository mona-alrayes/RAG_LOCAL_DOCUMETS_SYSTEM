from collections.abc import Mapping, Sequence
from enum import StrEnum

from pydantic import BaseModel, ConfigDict, Field, field_validator

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.parsing.normalized import NormalizedDocument
from app.processing.base import ProcessingProfile
from app.processing.chunks import NormalizedChunk
from app.processing.cloud_embeddings import CLOUD_EMBEDDING_DIMENSION
from app.processing.cloud_sparse import (
    CLOUD_SPARSE_MODEL,
    CLOUD_SPARSE_TOKENIZER,
)
from app.processing.local_embeddings import LOCAL_EMBEDDING_DIMENSION
from app.processing.local_sparse import (
    LOCAL_BM25_LANGUAGE,
    LOCAL_BM25_MODEL,
)


class ProcessingStage(StrEnum):
    PARSE = "parse"
    CHUNK = "chunk"
    DENSE_EMBEDDING = "dense_embedding"
    SPARSE_REPRESENTATION = "sparse_representation"
    TOTAL = "total"


class ChunkingSnapshot(BaseModel):
    model_config = ConfigDict(frozen=True)

    chunk_size: int = Field(ge=1)
    chunk_overlap: int = Field(ge=0)


class DenseEmbeddingSnapshot(BaseModel):
    model_config = ConfigDict(frozen=True)

    provider: str
    model: str
    vector_dimension: int = Field(ge=1)


class SparseRepresentationSnapshot(BaseModel):
    model_config = ConfigDict(frozen=True)

    provider: str
    model: str
    tokenizer: str | None = None
    language: str | None = None
    disable_stemmer: bool | None = None


class CloudBatchingSnapshot(BaseModel):
    model_config = ConfigDict(frozen=True)

    batch_size: int = Field(ge=1)
    wait_between_batches_seconds: float = Field(ge=0)
    rate_limit_retry_wait_seconds: float = Field(ge=0)
    max_retries: int = Field(ge=0)


class ProcessingProfileSnapshot(BaseModel):
    model_config = ConfigDict(frozen=True)

    profile: ProcessingProfile
    chunking: ChunkingSnapshot
    dense_embedding: DenseEmbeddingSnapshot
    sparse_representation: SparseRepresentationSnapshot
    batching: CloudBatchingSnapshot | None = None


class ProcessingWarning(BaseModel):
    model_config = ConfigDict(frozen=True)

    code: str = Field(
        min_length=1,
        max_length=100,
        pattern=r"^[a-z0-9]+(?:_[a-z0-9]+)*$",
    )
    message: str = Field(min_length=1, max_length=300)
    stage: ProcessingStage | None = None

    @field_validator("message")
    @classmethod
    def validate_safe_message(cls, value: str) -> str:
        message = value.strip()

        if not message:
            raise ValueError("Warning message must not be blank.")

        if "\n" in message or "\r" in message:
            raise ValueError("Warning message must be a single line.")

        if any(ord(character) < 32 for character in message):
            raise ValueError("Warning message must not contain control characters.")

        return message


class ProcessingReport(BaseModel):
    model_config = ConfigDict(frozen=True)

    profile_snapshot: ProcessingProfileSnapshot
    total_pages: int | None = Field(default=None, ge=0)
    total_chunks: int = Field(ge=0)
    vector_count: int = Field(ge=0)
    vector_dimension: int | None = Field(default=None, ge=1)
    stage_timings_ms: dict[ProcessingStage, int] = Field(default_factory=dict)
    warnings: list[ProcessingWarning] = Field(default_factory=list)


def build_profile_snapshot(
    *,
    profile: ProcessingProfile,
    settings: Settings,
) -> ProcessingProfileSnapshot:
    chunking = ChunkingSnapshot(
        chunk_size=settings.chunk_size,
        chunk_overlap=settings.chunk_overlap,
    )

    if profile is ProcessingProfile.CLOUD:
        return ProcessingProfileSnapshot(
            profile=profile,
            chunking=chunking,
            dense_embedding=DenseEmbeddingSnapshot(
                provider="jina",
                model=settings.cloud_embed_model,
                vector_dimension=CLOUD_EMBEDDING_DIMENSION,
            ),
            sparse_representation=SparseRepresentationSnapshot(
                provider="qdrant",
                model=CLOUD_SPARSE_MODEL,
                tokenizer=CLOUD_SPARSE_TOKENIZER,
            ),
            batching=CloudBatchingSnapshot(
                batch_size=settings.embed_batch_size,
                wait_between_batches_seconds=settings.wait_between_batches,
                rate_limit_retry_wait_seconds=settings.rate_limit_retry_wait,
                max_retries=settings.max_retries,
            ),
        )

    if profile is ProcessingProfile.HYBRID_LOCAL:
        return ProcessingProfileSnapshot(
            profile=profile,
            chunking=chunking,
            dense_embedding=DenseEmbeddingSnapshot(
                provider="transformers",
                model=settings.local_embed_model,
                vector_dimension=LOCAL_EMBEDDING_DIMENSION,
            ),
            sparse_representation=SparseRepresentationSnapshot(
                provider="fastembed",
                model=LOCAL_BM25_MODEL,
                language=LOCAL_BM25_LANGUAGE,
                disable_stemmer=True,
            ),
        )

    raise ApplicationException(
        code="processing_profile_snapshot_unsupported",
        message="Processing profile is not supported for reporting.",
    )


class ProcessingReportBuilder:
    def build(
        self,
        *,
        profile_snapshot: ProcessingProfileSnapshot,
        documents: Sequence[NormalizedDocument],
        chunks: Sequence[NormalizedChunk],
        dense_vectors: Sequence[Sequence[float]],
        stage_timings_ms: Mapping[ProcessingStage | str, int],
        warnings: Sequence[ProcessingWarning] = (),
    ) -> ProcessingReport:
        vector_count, vector_dimension = self._summarize_vectors(
            chunks=chunks,
            dense_vectors=dense_vectors,
            expected_dimension=profile_snapshot.dense_embedding.vector_dimension,
        )

        return ProcessingReport(
            profile_snapshot=profile_snapshot,
            total_pages=self._count_pages(documents),
            total_chunks=len(chunks),
            vector_count=vector_count,
            vector_dimension=vector_dimension,
            stage_timings_ms=self._normalize_stage_timings(stage_timings_ms),
            warnings=list(warnings),
        )

    @staticmethod
    def _count_pages(
        documents: Sequence[NormalizedDocument],
    ) -> int | None:
        if not documents:
            return None

        pages = [document.page for document in documents]

        if any(page is None for page in pages):
            return None

        return len(set(pages))

    @staticmethod
    def _summarize_vectors(
        *,
        chunks: Sequence[NormalizedChunk],
        dense_vectors: Sequence[Sequence[float]],
        expected_dimension: int,
    ) -> tuple[int, int | None]:
        vector_count = len(dense_vectors)

        if vector_count != len(chunks):
            raise ApplicationException(
                code="processing_report_vector_count_mismatch",
                message="Dense vector count does not match chunk count.",
            )

        if not dense_vectors:
            return 0, None

        vector_dimension = len(dense_vectors[0])

        if vector_dimension != expected_dimension:
            raise ApplicationException(
                code="processing_report_vector_dimension_mismatch",
                message="Dense vector dimension does not match the processing profile.",
            )

        if any(len(vector) != vector_dimension for vector in dense_vectors):
            raise ApplicationException(
                code="processing_report_inconsistent_vector_dimensions",
                message="Dense vectors do not have a consistent dimension.",
            )

        return vector_count, vector_dimension

    @staticmethod
    def _normalize_stage_timings(
        stage_timings_ms: Mapping[ProcessingStage | str, int],
    ) -> dict[ProcessingStage, int]:
        normalized: dict[ProcessingStage, int] = {}

        for raw_stage, duration_ms in stage_timings_ms.items():
            try:
                stage = (
                    raw_stage
                    if isinstance(raw_stage, ProcessingStage)
                    else ProcessingStage(raw_stage)
                )
            except ValueError as exc:
                raise ApplicationException(
                    code="processing_report_unknown_stage",
                    message="Processing report contains an unknown stage timing.",
                ) from exc

            if stage in normalized:
                raise ApplicationException(
                    code="processing_report_duplicate_stage",
                    message="Processing report contains a duplicate stage timing.",
                )

            if (
                isinstance(duration_ms, bool)
                or not isinstance(duration_ms, int)
                or duration_ms < 0
            ):
                raise ApplicationException(
                    code="processing_report_invalid_timing",
                    message="Processing stage timing must be a non-negative integer.",
                )

            normalized[stage] = duration_ms

        return normalized
