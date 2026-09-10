from collections.abc import Iterable
from importlib import import_module
from typing import Protocol

from qdrant_client import models

from app.core.exceptions import ApplicationException
from app.processing.chunks import NormalizedChunk


LOCAL_BM25_MODEL = "Qdrant/bm25"
LOCAL_BM25_LANGUAGE = "arabic"


class SparseEmbeddingResult(Protocol):
    indices: Iterable[int]
    values: Iterable[float]


class SparseEmbedder(Protocol):
    def embed(
        self,
        texts: list[str],
    ) -> Iterable[SparseEmbeddingResult]:
        ...


class LocalBm25Representer:
    def __init__(
        self,
        embedder: SparseEmbedder | None = None,
    ) -> None:
        if embedder is not None:
            self._embedder = embedder
            return

        try:
            fastembed = import_module("fastembed")
            self._embedder = fastembed.SparseTextEmbedding(
                model_name=LOCAL_BM25_MODEL,
                language=LOCAL_BM25_LANGUAGE,
                disable_stemmer=True,
            )
        except Exception as exc:
            raise ApplicationException(
                code="local_sparse_model_failed",
                message="Local BM25 model failed to load.",
            ) from exc

    def represent(
        self,
        chunks: list[NormalizedChunk],
    ) -> list[models.SparseVector]:
        return self._represent_texts(
            [chunk.text for chunk in chunks]
        )

    def represent_query(
        self,
        question: str,
    ) -> models.SparseVector:
        vectors = self._represent_texts(
            [question.strip()]
        )

        return vectors[0]

    def _represent_texts(
        self,
        texts: list[str],
    ) -> list[models.SparseVector]:
        if not texts:
            return []

        try:
            embeddings = list(
                self._embedder.embed(texts)
            )
        except Exception as exc:
            raise ApplicationException(
                code="local_sparse_model_failed",
                message="Local BM25 representation failed.",
            ) from exc

        if len(embeddings) != len(texts):
            raise ApplicationException(
                code="local_sparse_result_invalid",
                message=(
                    "Local BM25 result count does not "
                    "match input count."
                ),
            )

        vectors: list[models.SparseVector] = []

        for embedding in embeddings:
            indices = [
                int(index)
                for index in embedding.indices
            ]
            values = [
                float(value)
                for value in embedding.values
            ]

            if len(indices) != len(values):
                raise ApplicationException(
                    code="local_sparse_result_invalid",
                    message=(
                        "Local BM25 sparse indices and values "
                        "must have equal length."
                    ),
                )

            vectors.append(
                models.SparseVector(
                    indices=indices,
                    values=values,
                )
            )

        return vectors
