import time
from collections.abc import Callable
from typing import Protocol

from app.core.exceptions import ApplicationException
from app.processing.cloud_embeddings import (
    CLOUD_EMBEDDING_DIMENSION,
    DEFAULT_MAX_RETRIES,
    DEFAULT_RATE_LIMIT_RETRY_WAIT,
)
from app.processing.jina_provider import (
    JinaEmbeddingProvider,
    JinaProviderError,
)


JINA_QUERY_TASK = "retrieval.query"


class CloudQueryEmbeddingProvider(Protocol):
    def embed(self, texts: list[str]) -> list[list[float]]: ...


class CloudJinaQueryEmbedder:
    def __init__(
        self,
        api_key: str,
        model: str,
        *,
        rate_limit_retry_wait: float = DEFAULT_RATE_LIMIT_RETRY_WAIT,
        max_retries: int = DEFAULT_MAX_RETRIES,
        sleeper: Callable[[float], None] = time.sleep,
        provider: CloudQueryEmbeddingProvider | None = None,
    ) -> None:
        self._rate_limit_retry_wait = rate_limit_retry_wait
        self._max_retries = max_retries
        self._sleep = sleeper

        self._provider = provider or JinaEmbeddingProvider(
            api_key=api_key,
            model=model,
            task=JINA_QUERY_TASK,
            dimensions=CLOUD_EMBEDDING_DIMENSION,
        )

    def embed(self, question: str) -> list[float]:
        normalized_question = question.strip()

        if not normalized_question:
            raise ApplicationException(
                code="cloud_query_invalid",
                message="Cloud retrieval question must not be blank.",
            )

        vectors = self._embed_with_retry(normalized_question)

        if (
            not isinstance(vectors, list)
            or len(vectors) != 1
            or not isinstance(vectors[0], list)
            or len(vectors[0]) != CLOUD_EMBEDDING_DIMENSION
        ):
            raise ApplicationException(
                code="cloud_query_embedding_result_invalid",
                message=(
                    "Cloud query embedding result must contain exactly "
                    f"one {CLOUD_EMBEDDING_DIMENSION}-dimension vector."
                ),
            )

        try:
            return [float(value) for value in vectors[0]]
        except (TypeError, ValueError) as exc:
            raise ApplicationException(
                code="cloud_query_embedding_result_invalid",
                message="Cloud query embedding result is invalid.",
            ) from exc

    def _embed_with_retry(
        self,
        question: str,
    ) -> list[list[float]]:
        retries = 0

        while True:
            try:
                return self._provider.embed([question])
            except JinaProviderError as exc:
                if not exc.retryable or retries >= self._max_retries:
                    raise ApplicationException(
                        code="cloud_query_embedding_provider_failed",
                        message="Cloud query embedding provider failed.",
                    ) from exc

                retries += 1

                if self._rate_limit_retry_wait > 0:
                    self._sleep(self._rate_limit_retry_wait)
            except Exception as exc:
                raise ApplicationException(
                    code="cloud_query_embedding_provider_failed",
                    message="Cloud query embedding provider failed.",
                ) from exc
