from dataclasses import dataclass
from collections.abc import Sequence

from qdrant_client import QdrantClient

from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.persistence import (
    PointScope,
    count_points,
    upsert_points,
)
from app.infrastructure.qdrant.points import (
    PointPayload,
    SparseRepresentation,
    build_point,
)
from app.processing.chunks import NormalizedChunk


@dataclass(frozen=True, slots=True)
class IndexingContext:
    user_id: int
    document_id: int
    processing_run_id: int
    processing_profile: str
    file_type: str
    source: str
    collection_name: str


@dataclass(frozen=True, slots=True)
class IndexingResult:
    collection_name: str
    vector_count: int


class QdrantDocumentIndexer:
    def __init__(self, client: QdrantClient) -> None:
        self._client = client

    def index(
        self,
        *,
        context: IndexingContext,
        chunks: Sequence[NormalizedChunk],
        dense_vectors: Sequence[Sequence[float]],
        sparse_representations: Sequence[SparseRepresentation],
    ) -> IndexingResult:
        self._validate_counts(
            chunks=chunks,
            dense_vectors=dense_vectors,
            sparse_representations=sparse_representations,
        )

        points = [
            build_point(
                payload=PointPayload(
                    user_id=context.user_id,
                    document_id=context.document_id,
                    processing_run_id=context.processing_run_id,
                    processing_profile=context.processing_profile,
                    file_type=context.file_type,
                    source=context.source,
                    page=chunk.page,
                    section=chunk.section,
                    chunk_index=chunk_index,
                    text=chunk.text,
                ),
                dense_vector=dense_vector,
                sparse_representation=sparse_representation,
            )
            for chunk_index, (
                chunk,
                dense_vector,
                sparse_representation,
            ) in enumerate(
                zip(
                    chunks,
                    dense_vectors,
                    sparse_representations,
                    strict=True,
                )
            )
        ]

        if points:
            upsert_points(
                client=self._client,
                collection_name=context.collection_name,
                points=points,
            )

        scope = PointScope(
            user_id=context.user_id,
            document_id=context.document_id,
            processing_run_id=context.processing_run_id,
        )

        persisted_count = count_points(
            client=self._client,
            collection_name=context.collection_name,
            scope=scope,
        )

        if persisted_count != len(points):
            raise ApplicationException(
                code="qdrant_index_count_mismatch",
                message=(
                    "Persisted Qdrant point count does not match "
                    "the processing run chunk count."
                ),
            )

        return IndexingResult(
            collection_name=context.collection_name,
            vector_count=persisted_count,
        )

    @staticmethod
    def _validate_counts(
        *,
        chunks: Sequence[NormalizedChunk],
        dense_vectors: Sequence[Sequence[float]],
        sparse_representations: Sequence[SparseRepresentation],
    ) -> None:
        if len(dense_vectors) != len(chunks):
            raise ApplicationException(
                code="qdrant_index_dense_count_mismatch",
                message="Dense vector count does not match chunk count.",
            )

        if len(sparse_representations) != len(chunks):
            raise ApplicationException(
                code="qdrant_index_sparse_count_mismatch",
                message="Sparse representation count does not match chunk count.",
            )
