from dataclasses import dataclass
from typing import Sequence
from uuid import NAMESPACE_URL, UUID, uuid5

from qdrant_client import models

from app.infrastructure.qdrant.schema import (
    DENSE_VECTOR_NAME,
    SPARSE_VECTOR_NAME,
)


SparseRepresentation = models.SparseVector | models.Document


@dataclass(frozen=True, slots=True)
class PointPayload:
    user_id: int
    document_id: int
    processing_run_id: int
    processing_profile: str
    file_type: str
    source: str
    page: int | None
    section: str | None
    chunk_index: int
    text: str

    def as_dict(self) -> dict[str, object]:
        return {
            "user_id": self.user_id,
            "document_id": self.document_id,
            "processing_run_id": self.processing_run_id,
            "processing_profile": self.processing_profile,
            "file_type": self.file_type,
            "source": self.source,
            "page": self.page,
            "section": self.section,
            "chunk_index": self.chunk_index,
            "text": self.text,
        }


def build_point_id(payload: PointPayload) -> UUID:
    identity = (
        f"rag-point:"
        f"{payload.user_id}:"
        f"{payload.document_id}:"
        f"{payload.processing_run_id}:"
        f"{payload.processing_profile}:"
        f"{payload.chunk_index}"
    )

    return uuid5(NAMESPACE_URL, identity)


def build_point(
    *,
    payload: PointPayload,
    dense_vector: Sequence[float],
    sparse_representation: SparseRepresentation,
) -> models.PointStruct:
    return models.PointStruct(
        id=str(build_point_id(payload)),
        vector={
            DENSE_VECTOR_NAME: list(dense_vector),
            SPARSE_VECTOR_NAME: sparse_representation,
        },
        payload=payload.as_dict(),
    )
