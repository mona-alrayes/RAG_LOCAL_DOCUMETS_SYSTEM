import time
from collections.abc import Callable
from typing import Protocol

from app.core.exceptions import ApplicationException
from app.processing.chunks import NormalizedChunk
from app.processing.jina_provider import (
    JinaEmbeddingProvider,
    JinaProviderError,
)


JINA_PASSAGE_TASK = "retrieval.passage"
CLOUD_EMBEDDING_DIMENSION = 1024

DEFAULT_EMBED_BATCH_SIZE = 6
DEFAULT_WAIT_BETWEEN_BATCHES = 3.0
DEFAULT_RATE_LIMIT_RETRY_WAIT = 30.0
DEFAULT_MAX_RETRIES = 5


class CloudEmbeddingProvider(Protocol):
    def embed(self, texts: list[str]) -> list[list[float]]: ...


class CloudJinaEmbedder:
    def __init__(
        self,
        api_key: str,
        model: str,
        *,
        batch_size: int = DEFAULT_EMBED_BATCH_SIZE,
        wait_between_batches: float = DEFAULT_WAIT_BETWEEN_BATCHES,
        rate_limit_retry_wait: float = DEFAULT_RATE_LIMIT_RETRY_WAIT,
        max_retries: int = DEFAULT_MAX_RETRIES,
        sleeper: Callable[[float], None] = time.sleep,
        provider: CloudEmbeddingProvider | None = None,
    ) -> None:
        self._batch_size = batch_size
        self._wait_between_batches = wait_between_batches
        self._rate_limit_retry_wait = rate_limit_retry_wait
        self._max_retries = max_retries
        self._sleep = sleeper

        self._provider = provider or JinaEmbeddingProvider(
            api_key=api_key,
            model=model,
            task=JINA_PASSAGE_TASK,
            dimensions=CLOUD_EMBEDDING_DIMENSION,
        )

    def embed(self, chunks: list[NormalizedChunk]) -> list[list[float]]:
        if not chunks:
            return []

        vectors: list[list[float]] = []

        for batch_start in range(0, len(chunks), self._batch_size):
            batch = chunks[batch_start : batch_start + self._batch_size]
            batch_vectors = self._embed_batch_with_retry(batch)

            self._validate_vectors(
                vectors=batch_vectors,
                expected_count=len(batch),
            )

            vectors.extend(batch_vectors)

            has_more_batches = batch_start + self._batch_size < len(chunks)
            if has_more_batches and self._wait_between_batches > 0:
                self._sleep(self._wait_between_batches)

        self._validate_vectors(
            vectors=vectors,
            expected_count=len(chunks),
        )

        return vectors

    def _embed_batch_with_retry(
        self,
        chunks: list[NormalizedChunk],
    ) -> list[list[float]]:
        texts = [chunk.text for chunk in chunks]
        retries = 0

        while True:
            try:
                return self._provider.embed(texts)
            except JinaProviderError as exc:
                if not exc.retryable or retries >= self._max_retries:
                    raise ApplicationException(
                        code="cloud_embedding_provider_failed",
                        message="Cloud embedding provider failed.",
                    ) from exc

                retries += 1

                if self._rate_limit_retry_wait > 0:
                    self._sleep(self._rate_limit_retry_wait)
            except Exception as exc:
                raise ApplicationException(
                    code="cloud_embedding_provider_failed",
                    message="Cloud embedding provider failed.",
                ) from exc

    @staticmethod
    def _validate_vectors(
        *,
        vectors: list[list[float]],
        expected_count: int,
    ) -> None:
        if len(vectors) != expected_count:
            raise ApplicationException(
                code="cloud_embedding_result_invalid",
                message="Cloud embedding result count does not match chunk count.",
            )

        if any(
            len(vector) != CLOUD_EMBEDDING_DIMENSION
            for vector in vectors
        ):
            raise ApplicationException(
                code="cloud_embedding_result_invalid",
                message=(
                    "Cloud embedding vector dimension must be "
                    f"{CLOUD_EMBEDDING_DIMENSION}."
                ),
            )
