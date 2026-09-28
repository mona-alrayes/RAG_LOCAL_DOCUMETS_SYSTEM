import json
import math
from typing import Any

import pytest

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.processing.base import ProcessingProfile
from app.processing.cloud_reranking import (
    CloudJinaReranker,
)
from app.processing.jina_provider import (
    JINA_RERANK_URL,
    JinaProviderError,
    JinaRerankerProvider,
    JinaRerankResult,
)
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
    CloudRetrievalService,
    CloudRetrievalTarget,
)

RERANK_MODEL = (
    "jina-reranker-v2-base-multilingual"
)


def _candidate(
    point_id: str,
    chunk_index: int,
    *,
    text: str | None = None,
) -> CloudRetrievalResult:
    return CloudRetrievalResult(
        point_id=point_id,
        retrieval_score=0.5 + (chunk_index / 100),
        document_id=12,
        processing_run_id=81,
        processing_profile=(
            ProcessingProfile.CLOUD
        ),
        chunk_index=chunk_index,
        text=(
            text
            if text is not None
            else f"chunk-{chunk_index}"
        ),
        page=chunk_index,
        section="section",
        source="document.pdf",
    )


class StaticRerankProvider:
    def __init__(
        self,
        results: Any,
    ) -> None:
        self.results = results
        self.calls: list[
            dict[str, Any]
        ] = []

    def rerank(
        self,
        *,
        query: str,
        documents: list[str],
        top_n: int,
    ) -> Any:
        self.calls.append(
            {
                "query": query,
                "documents": documents,
                "top_n": top_n,
            }
        )

        return self.results


def test_settings_use_approved_cloud_reranker_model() -> None:
    assert (
        Settings().cloud_rerank_model
        == RERANK_MODEL
    )


def test_jina_reranker_provider_request_contract(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    response_body = {
        "model": RERANK_MODEL,
        "results": [
            {
                "index": 1,
                "relevance_score": 0.9,
            },
            {
                "index": 0,
                "relevance_score": 0.2,
            },
        ],
    }

    captured: dict[str, Any] = {}

    class FakeResponse:
        def __enter__(self) -> "FakeResponse":
            return self

        def __exit__(
            self,
            exc_type: Any,
            exc_value: Any,
            traceback: Any,
        ) -> bool:
            return False

        def read(self) -> bytes:
            return json.dumps(
                response_body
            ).encode("utf-8")

    def fake_urlopen(request: Any) -> FakeResponse:
        captured["url"] = request.full_url
        captured["authorization"] = (
            request.get_header(
                "Authorization"
            )
        )
        captured["body"] = json.loads(
            request.data.decode("utf-8")
        )
        return FakeResponse()

    monkeypatch.setattr(
        "app.processing.jina_provider.urlopen",
        fake_urlopen,
    )

    provider = JinaRerankerProvider(
        api_key="secret-key",
        model=RERANK_MODEL,
    )

    results = provider.rerank(
        query="ما شروط فسخ العقد؟",
        documents=[
            "candidate A",
            "candidate B",
        ],
        top_n=2,
    )

    assert captured["url"] == JINA_RERANK_URL
    assert (
        captured["authorization"]
        == "Bearer secret-key"
    )
    assert captured["body"] == {
        "model": RERANK_MODEL,
        "query": "ما شروط فسخ العقد؟",
        "documents": [
            "candidate A",
            "candidate B",
        ],
        "top_n": 2,
        "return_documents": False,
    }

    assert results == [
        JinaRerankResult(
            index=1,
            relevance_score=0.9,
        ),
        JinaRerankResult(
            index=0,
            relevance_score=0.2,
        ),
    ]


def test_cloud_jina_reranker_reorders_original_candidates() -> None:
    candidate_a = _candidate(
        "A",
        1,
        text="same text",
    )
    candidate_b = _candidate(
        "B",
        2,
        text="same text",
    )
    candidate_c = _candidate(
        "C",
        3,
    )

    provider = StaticRerankProvider(
        [
            JinaRerankResult(
                index=1,
                relevance_score=0.95,
            ),
            JinaRerankResult(
                index=2,
                relevance_score=0.75,
            ),
            JinaRerankResult(
                index=0,
                relevance_score=0.10,
            ),
        ]
    )

    reranker = CloudJinaReranker(
        api_key="secret",
        model=RERANK_MODEL,
        provider=provider,
    )

    results = reranker.rerank(
        question="السؤال الأصلي",
        candidates=[
            candidate_a,
            candidate_b,
            candidate_c,
        ],
        limit=3,
    )

    assert [
        result.point_id
        for result in results
    ] == [
        "B",
        "C",
        "A",
    ]

    assert [
        result.retrieval_score
        for result in results
    ] == [
        candidate_b.retrieval_score,
        candidate_c.retrieval_score,
        candidate_a.retrieval_score,
    ]

    assert [
        result.reranker_score
        for result in results
    ] == [
        0.95,
        0.75,
        0.10,
    ]

    assert provider.calls == [
        {
            "query": "السؤال الأصلي",
            "documents": [
                "same text",
                "same text",
                "chunk-3",
            ],
            "top_n": 3,
        }
    ]


def test_cloud_jina_reranker_applies_final_limit_after_candidates() -> None:
    candidates = [
        _candidate("A", 1),
        _candidate("B", 2),
        _candidate("C", 3),
    ]

    provider = StaticRerankProvider(
        [
            JinaRerankResult(
                index=2,
                relevance_score=0.9,
            ),
            JinaRerankResult(
                index=1,
                relevance_score=0.8,
            ),
        ]
    )

    reranker = CloudJinaReranker(
        api_key="secret",
        model=RERANK_MODEL,
        provider=provider,
    )

    results = reranker.rerank(
        question="سؤال",
        candidates=candidates,
        limit=2,
    )

    assert [
        result.point_id
        for result in results
    ] == [
        candidates[2].point_id,
        candidates[1].point_id,
    ]

    assert [
        result.reranker_score
        for result in results
    ] == [
        0.9,
        0.8,
    ]

    assert provider.calls[0][
        "top_n"
    ] == 2


def test_cloud_jina_reranker_skips_empty_candidates() -> None:
    provider = StaticRerankProvider([])

    reranker = CloudJinaReranker(
        api_key="secret",
        model=RERANK_MODEL,
        provider=provider,
    )

    assert (
        reranker.rerank(
            question="سؤال",
            candidates=[],
            limit=5,
        )
        == []
    )

    assert provider.calls == []


@pytest.mark.parametrize(
    "provider_results",
    [
        [
            JinaRerankResult(
                index=0,
                relevance_score=0.9,
            )
        ],
        [
            JinaRerankResult(
                index=99,
                relevance_score=0.9,
            ),
            JinaRerankResult(
                index=0,
                relevance_score=0.8,
            ),
        ],
        [
            JinaRerankResult(
                index=0,
                relevance_score=0.9,
            ),
            JinaRerankResult(
                index=0,
                relevance_score=0.8,
            ),
        ],
        [
            JinaRerankResult(
                index=0,
                relevance_score=math.nan,
            ),
            JinaRerankResult(
                index=1,
                relevance_score=0.8,
            ),
        ],
    ],
    ids=[
        "missing-result",
        "out-of-range-index",
        "duplicate-index",
        "non-finite-score",
    ],
)
def test_cloud_jina_reranker_fails_closed_on_invalid_mapping(
    provider_results: Any,
) -> None:
    reranker = CloudJinaReranker(
        api_key="secret",
        model=RERANK_MODEL,
        provider=StaticRerankProvider(
            provider_results
        ),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        reranker.rerank(
            question="سؤال",
            candidates=[
                _candidate("A", 1),
                _candidate("B", 2),
            ],
            limit=2,
        )

    assert (
        exc_info.value.code
        == "cloud_reranker_result_invalid"
    )


def test_cloud_jina_reranker_maps_malformed_provider_response() -> None:
    class FailingProvider:
        def rerank(
            self,
            *,
            query: str,
            documents: list[str],
            top_n: int,
        ) -> list[JinaRerankResult]:
            raise JinaProviderError(
                retryable=False
            )

    reranker = CloudJinaReranker(
        api_key="super-secret-key",
        model=RERANK_MODEL,
        provider=FailingProvider(),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        reranker.rerank(
            question="سؤال",
            candidates=[
                _candidate("A", 1),
            ],
            limit=1,
        )

    assert (
        exc_info.value.code
        == "cloud_reranker_provider_failed"
    )
    assert (
        exc_info.value.message
        == "Cloud reranker provider failed."
    )
    assert (
        "super-secret-key"
        not in exc_info.value.message
    )


def test_cloud_jina_reranker_preserves_retry_policy() -> None:
    class RetryProvider:
        def __init__(self) -> None:
            self.attempts = 0

        def rerank(
            self,
            *,
            query: str,
            documents: list[str],
            top_n: int,
        ) -> list[JinaRerankResult]:
            self.attempts += 1

            if self.attempts == 1:
                raise JinaProviderError(
                    retryable=True
                )

            return [
                JinaRerankResult(
                    index=0,
                    relevance_score=0.9,
                )
            ]

    provider = RetryProvider()
    sleeps: list[float] = []

    reranker = CloudJinaReranker(
        api_key="secret",
        model=RERANK_MODEL,
        max_retries=1,
        rate_limit_retry_wait=0.25,
        sleeper=sleeps.append,
        provider=provider,
    )

    result = reranker.rerank(
        question="سؤال",
        candidates=[
            _candidate("A", 1),
        ],
        limit=1,
    )

    assert result[0].point_id == "A"
    assert provider.attempts == 2
    assert sleeps == [0.25]


def test_cloud_retrieval_expands_rrf_candidates_before_reranking() -> None:
    candidates = [
        _candidate("A", 1),
        _candidate("B", 2),
        _candidate("C", 3),
    ]

    class QueryEmbedder:
        def embed(
            self,
            question: str,
        ) -> list[float]:
            return [0.1, 0.2]

    class Retriever:
        def __init__(self) -> None:
            self.call: (
                dict[str, Any] | None
            ) = None

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[CloudRetrievalResult]:
            self.call = kwargs
            return candidates

    class Reranker:
        def __init__(self) -> None:
            self.call: (
                dict[str, Any] | None
            ) = None

        def rerank(
            self,
            **kwargs: Any,
        ) -> list[CloudRetrievalResult]:
            self.call = kwargs
            return [
                candidates[1],
                candidates[0],
            ]

    retriever = Retriever()
    reranker = Reranker()

    service = CloudRetrievalService(
        settings=Settings(
            qdrant_cloud_collection=(
                "cloud-k6"
            ),
            rag_rerank_candidate_multiplier=3,
        ),
        query_embedder=QueryEmbedder(),
        dense_retriever=retriever,
        reranker=reranker,
    )

    target = CloudRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=(
            ProcessingProfile.CLOUD
        ),
    )

    result = service.retrieve(
        user_id=7,
        target=target,
        question="السؤال الأصلي",
        limit=6,
    )

    assert result == [
        candidates[1],
        candidates[0],
    ]

    assert retriever.call is not None
    assert (
        retriever.call["limit"]
        == (
            6
            * 3
        )
    )
    assert (
        retriever.call["target"]
        == target
    )
    assert (
        retriever.call["user_id"]
        == 7
    )

    assert reranker.call is not None
    assert (
        reranker.call["question"]
        == "السؤال الأصلي"
    )
    assert (
        reranker.call["candidates"]
        is candidates
    )
    assert reranker.call["limit"] == 6


def test_cloud_retrieval_does_not_call_reranker_for_empty_candidates() -> None:
    class QueryEmbedder:
        def embed(
            self,
            question: str,
        ) -> list[float]:
            return [0.1, 0.2]

    class Retriever:
        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[CloudRetrievalResult]:
            return []

    class Reranker:
        def __init__(self) -> None:
            self.called = False

        def rerank(
            self,
            **kwargs: Any,
        ) -> list[CloudRetrievalResult]:
            self.called = True
            return []

    reranker = Reranker()

    service = CloudRetrievalService(
        settings=Settings(),
        query_embedder=QueryEmbedder(),
        dense_retriever=Retriever(),
        reranker=reranker,
    )

    result = service.retrieve(
        user_id=7,
        target=CloudRetrievalTarget(
            document_id=12,
            processing_run_id=81,
            processing_profile=(
                ProcessingProfile.CLOUD
            ),
        ),
        question="سؤال",
        limit=5,
    )

    assert result == []
    assert reranker.called is False


def test_cloud_retrieval_rejects_hybrid_local_before_reranker() -> None:
    class Dependency:
        def __init__(self) -> None:
            self.called = False

        def embed(
            self,
            question: str,
        ) -> list[float]:
            self.called = True
            return []

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.called = True
            return []

        def rerank(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.called = True
            return []

    dependency = Dependency()

    service = CloudRetrievalService(
        settings=Settings(),
        query_embedder=dependency,
        dense_retriever=dependency,
        reranker=dependency,
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        service.retrieve(
            user_id=7,
            target=CloudRetrievalTarget(
                document_id=12,
                processing_run_id=81,
                processing_profile=(
                    ProcessingProfile.HYBRID_LOCAL
                ),
            ),
            question="سؤال",
            limit=5,
        )

    assert (
        exc_info.value.code
        == "cloud_retrieval_target_invalid"
    )
    assert dependency.called is False
