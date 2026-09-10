from collections.abc import Callable
from typing import Protocol

from qdrant_client import models

from app.parsing.normalized import NormalizedDocument
from app.processing.base import BaseProcessingProfile, ProcessingProfile
from app.processing.chunks import NormalizedChunk


SparseRepresentations = (
    list[models.Document]
    | list[models.SparseVector]
)


class Chunker(Protocol):
    def chunk(
        self,
        documents: list[NormalizedDocument],
    ) -> list[NormalizedChunk]:
        ...


class DenseEmbedder(Protocol):
    def embed(
        self,
        chunks: list[NormalizedChunk],
    ) -> list[list[float]]:
        ...


class SparseRepresenter(Protocol):
    def represent(
        self,
        chunks: list[NormalizedChunk],
    ) -> SparseRepresentations:
        ...


class ExecutableProcessingProfile(BaseProcessingProfile):
    def __init__(
        self,
        *,
        profile: ProcessingProfile,
        chunker: Chunker,
        dense_embedder: DenseEmbedder,
        sparse_representer_factory: Callable[[], SparseRepresenter],
    ) -> None:
        self._profile = profile
        self._chunker = chunker
        self._dense_embedder = dense_embedder
        self._sparse_representer_factory = sparse_representer_factory

    @property
    def profile(self) -> ProcessingProfile:
        return self._profile

    def chunk(
        self,
        documents: list[NormalizedDocument],
    ) -> list[NormalizedChunk]:
        return self._chunker.chunk(documents)

    def embed(
        self,
        chunks: list[NormalizedChunk],
    ) -> list[list[float]]:
        return self._dense_embedder.embed(chunks)

    def represent_sparse(
        self,
        chunks: list[NormalizedChunk],
    ) -> SparseRepresentations:
        representer = self._sparse_representer_factory()

        return representer.represent(chunks)
