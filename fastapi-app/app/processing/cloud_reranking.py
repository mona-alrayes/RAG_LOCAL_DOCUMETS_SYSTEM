from dataclasses import replace
import math
import time
from collections.abc import Callable
from typing import Protocol

from app.core.exceptions import ApplicationException
from app.processing.cloud_embeddings import (
    DEFAULT_MAX_RETRIES,
    DEFAULT_RATE_LIMIT_RETRY_WAIT,
)
from app.processing.jina_provider import (
    JinaProviderError,
    JinaRerankerProvider,
    JinaRerankResult,
)
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
)


class CloudRerankingProvider(Protocol):
    def rerank(
        self,
        *,
        query: str,
        documents: list[str],
        top_n: int,
    ) -> list[JinaRerankResult]:
        ...


class CloudJinaReranker:
    def __init__(
        self,
        api_key: str,
        model: str,
        *,
        rate_limit_retry_wait: float = (
            DEFAULT_RATE_LIMIT_RETRY_WAIT
        ),
        max_retries: int = DEFAULT_MAX_RETRIES,
        sleeper: Callable[
            [float],
            None,
        ] = time.sleep,
        provider: (
            CloudRerankingProvider | None
        ) = None,
    ) -> None:
        self._rate_limit_retry_wait = (
            rate_limit_retry_wait
        )
        self._max_retries = max_retries
        self._sleep = sleeper

        self._provider = (
            provider
            or JinaRerankerProvider(
                api_key=api_key,
                model=model,
            )
        )

    def rerank(
        self,
        *,
        question: str,
        candidates: list[
            CloudRetrievalResult
        ],
        limit: int,
    ) -> list[CloudRetrievalResult]:
        if not candidates or limit <= 0:
            return []

        if not question.strip():
            raise ApplicationException(
                code=(
                    "cloud_reranker_question_invalid"
                ),
                message=(
                    "Cloud reranker question "
                    "must not be blank."
                ),
            )

        top_n = min(
            limit,
            len(candidates),
        )

        results = self._rerank_with_retry(
            question=question,
            documents=[
                candidate.text
                for candidate in candidates
            ],
            top_n=top_n,
        )

        return self._map_results(
            candidates=candidates,
            results=results,
            expected_count=top_n,
        )

    def _rerank_with_retry(
        self,
        *,
        question: str,
        documents: list[str],
        top_n: int,
    ) -> list[JinaRerankResult]:
        retries = 0

        while True:
            try:
                return self._provider.rerank(
                    query=question,
                    documents=documents,
                    top_n=top_n,
                )
            except JinaProviderError as exc:
                if (
                    not exc.retryable
                    or retries
                    >= self._max_retries
                ):
                    raise ApplicationException(
                        code=(
                            "cloud_reranker_"
                            "provider_failed"
                        ),
                        message=(
                            "Cloud reranker "
                            "provider failed."
                        ),
                    ) from exc

                retries += 1

                if (
                    self._rate_limit_retry_wait
                    > 0
                ):
                    self._sleep(
                        self
                        ._rate_limit_retry_wait
                    )
            except Exception as exc:
                raise ApplicationException(
                    code=(
                        "cloud_reranker_"
                        "provider_failed"
                    ),
                    message=(
                        "Cloud reranker "
                        "provider failed."
                    ),
                ) from exc

    def _map_results(
        self,
        *,
        candidates: list[
            CloudRetrievalResult
        ],
        results: list[
            JinaRerankResult
        ],
        expected_count: int,
    ) -> list[CloudRetrievalResult]:
        if len(results) != expected_count:
            self._raise_invalid_result()

        seen_indexes: set[int] = set()

        for result in results:
            if not isinstance(
                result,
                JinaRerankResult,
            ):
                self._raise_invalid_result()

            if (
                result.index < 0
                or result.index
                >= len(candidates)
                or result.index
                in seen_indexes
                or not math.isfinite(
                    result.relevance_score
                )
            ):
                self._raise_invalid_result()

            seen_indexes.add(
                result.index
            )

        ranked_results = sorted(
            results,
            key=lambda result: (
                result.relevance_score
            ),
            reverse=True,
        )

        return [
            replace(
                candidates[result.index],
                reranker_score=float(
                    result.relevance_score
                ),
            )
            for result in ranked_results
        ]

    @staticmethod
    def _raise_invalid_result() -> None:
        raise ApplicationException(
            code="cloud_reranker_result_invalid",
            message=(
                "Cloud reranker result "
                "is invalid."
            ),
        )
