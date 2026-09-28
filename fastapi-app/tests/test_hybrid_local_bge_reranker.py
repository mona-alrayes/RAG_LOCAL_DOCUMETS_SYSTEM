from collections.abc import Callable, Iterator
from contextlib import contextmanager
from types import SimpleNamespace
from typing import Any

import pytest

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.schema import (
    DENSE_VECTOR_SIZE,
)
from app.processing.base import ProcessingProfile
from app.processing.local_reranking import (
    LocalBgeReranker,
)
from app.runtime.models import (
    LocalRuntimeSnapshot,
    ResourceSnapshot,
    RuntimeBackend,
    RuntimeDtype,
    RuntimeProbeStatus,
)
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
    CloudRetrievalService,
    CloudRetrievalTarget,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
    HybridLocalRetrievalService,
    HybridLocalRetrievalTarget,
)


def ready_runtime() -> LocalRuntimeSnapshot:
    return LocalRuntimeSnapshot(
        ready=True,
        requested_device="auto",
        selected_backend=RuntimeBackend.CPU,
        selected_dtype=RuntimeDtype.FP32,
        probe_status=RuntimeProbeStatus.PASSED,
        failure_reason=None,
        resources=ResourceSnapshot(),
    )


def candidate(
    *,
    point_id: str,
    text: str,
    score: float,
    chunk_index: int,
) -> HybridLocalRetrievalResult:
    return HybridLocalRetrievalResult(
        point_id=point_id,
        retrieval_score=score,
        document_id=12,
        processing_run_id=81,
        processing_profile=ProcessingProfile.HYBRID_LOCAL,
        chunk_index=chunk_index,
        text=text,
        page=1,
        section="section",
        source="document.pdf",
    )


class FakeInputTensor:
    def to(
        self,
        device: str,
    ) -> "FakeInputTensor":
        assert device == "cpu"
        return self


class FakeScoreTensor:
    def __init__(
        self,
        scores: Any,
    ) -> None:
        self._scores = scores

    def view(
        self,
        value: int,
    ) -> "FakeScoreTensor":
        assert value == -1
        return self

    def detach(self) -> "FakeScoreTensor":
        return self

    def cpu(self) -> "FakeScoreTensor":
        return self

    def float(self) -> "FakeScoreTensor":
        return self

    def tolist(self) -> Any:
        return self._scores


class FakeInferenceMode:
    def __enter__(self) -> None:
        return None

    def __exit__(
        self,
        exc_type: Any,
        exc_value: Any,
        traceback: Any,
    ) -> None:
        return None


class RecordingTokenizer:
    def __init__(self) -> None:
        self.pairs: list[list[str]] | None = None
        self.kwargs: dict[str, Any] | None = None

    def __call__(
        self,
        pairs: list[list[str]],
        **kwargs: Any,
    ) -> dict[str, FakeInputTensor]:
        self.pairs = pairs
        self.kwargs = kwargs

        return {
            "input_ids": FakeInputTensor(),
            "attention_mask": FakeInputTensor(),
        }


class StaticModel:
    def __init__(
        self,
        scores: Any,
    ) -> None:
        self._scores = scores

    def __call__(
        self,
        **kwargs: Any,
    ) -> Any:
        assert kwargs["return_dict"] is True

        return SimpleNamespace(
            logits=FakeScoreTensor(
                self._scores
            )
        )


class FailingModel:
    def __call__(
        self,
        **kwargs: Any,
    ) -> Any:
        raise RuntimeError(
            "private inference failure detail"
        )


class StaticCoordinator:
    def __init__(
        self,
        resource: Any,
    ) -> None:
        self.resource = resource
        self.model_ids: list[str] = []

    @contextmanager
    def lease(
        self,
        *,
        model_id: str,
        loader: Callable[[], Any],
        keep_alive_seconds: float = 0.0,
    ) -> Iterator[SimpleNamespace]:
        self.model_ids.append(model_id)

        yield SimpleNamespace(
            resource=self.resource
        )


class LoadingCoordinator:
    @contextmanager
    def lease(
        self,
        *,
        model_id: str,
        loader: Callable[[], Any],
        keep_alive_seconds: float = 0.0,
    ) -> Iterator[SimpleNamespace]:
        resource = loader()

        yield SimpleNamespace(
            resource=resource
        )


def resources(
    *,
    tokenizer: Any,
    model: Any,
) -> Any:
    return SimpleNamespace(
        tokenizer=tokenizer,
        model=model,
        torch=SimpleNamespace(
            inference_mode=lambda: FakeInferenceMode()
        ),
    )


def test_local_bge_reranker_orders_candidates_and_preserves_identity() -> None:
    tokenizer = RecordingTokenizer()

    candidates = [
        candidate(
            point_id="a",
            text="same text",
            score=0.11,
            chunk_index=1,
        ),
        candidate(
            point_id="b",
            text="same text",
            score=0.22,
            chunk_index=2,
        ),
        candidate(
            point_id="c",
            text="other text",
            score=0.33,
            chunk_index=3,
        ),
    ]

    coordinator = StaticCoordinator(
        resources(
            tokenizer=tokenizer,
            model=StaticModel(
                [0.2, 0.9, 0.4]
            ),
        )
    )

    reranker = LocalBgeReranker(
        model="BAAI/bge-reranker-v2-m3",
        runtime=ready_runtime(),
        coordinator=coordinator,
    )

    question = "  ما شروط العقد؟  "

    results = reranker.rerank(
        question=question,
        candidates=candidates,
        limit=2,
    )

    assert len(results) == 2

    assert results[0].point_id == candidates[1].point_id
    assert results[1].point_id == candidates[2].point_id

    assert results[0].retrieval_score == 0.22
    assert results[1].retrieval_score == 0.33

    assert results[0].reranker_score == 0.9
    assert results[1].reranker_score == 0.4

    assert tokenizer.pairs == [
        [question, "same text"],
        [question, "same text"],
        [question, "other text"],
    ]

    assert tokenizer.kwargs == {
        "padding": True,
        "truncation": True,
        "max_length": 1024,
        "return_tensors": "pt",
    }

    assert coordinator.model_ids == [
        "BAAI/bge-reranker-v2-m3"
    ]


def test_local_bge_reranker_empty_candidates_skip_model() -> None:
    coordinator = StaticCoordinator(
        resource=None
    )

    reranker = LocalBgeReranker(
        model="BAAI/bge-reranker-v2-m3",
        runtime=ready_runtime(),
        coordinator=coordinator,
    )

    results = reranker.rerank(
        question="سؤال",
        candidates=[],
        limit=5,
    )

    assert results == []
    assert coordinator.model_ids == []


@pytest.mark.parametrize(
    "scores",
    [
        [],
        [0.1],
        [0.1, float("nan")],
        [0.1, float("inf")],
        [0.1, "invalid"],
        [0.1, True],
    ],
)
def test_local_bge_reranker_rejects_invalid_scores(
    scores: Any,
) -> None:
    candidates = [
        candidate(
            point_id="a",
            text="A",
            score=0.1,
            chunk_index=1,
        ),
        candidate(
            point_id="b",
            text="B",
            score=0.2,
            chunk_index=2,
        ),
    ]

    reranker = LocalBgeReranker(
        model="BAAI/bge-reranker-v2-m3",
        runtime=ready_runtime(),
        coordinator=StaticCoordinator(
            resources(
                tokenizer=RecordingTokenizer(),
                model=StaticModel(scores),
            )
        ),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        reranker.rerank(
            question="سؤال",
            candidates=candidates,
            limit=2,
        )

    assert (
        exc_info.value.code
        == "local_reranker_result_invalid"
    )


def test_local_bge_reranker_rejects_blank_question() -> None:
    reranker = LocalBgeReranker(
        model="BAAI/bge-reranker-v2-m3",
        runtime=ready_runtime(),
        coordinator=StaticCoordinator(
            resource=None
        ),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        reranker.rerank(
            question="   ",
            candidates=[
                candidate(
                    point_id="a",
                    text="A",
                    score=0.1,
                    chunk_index=1,
                )
            ],
            limit=1,
        )

    assert (
        exc_info.value.code
        == "local_reranker_question_invalid"
    )


@pytest.mark.parametrize(
    "limit",
    [
        0,
        -1,
        True,
        1.5,
    ],
)
def test_local_bge_reranker_rejects_invalid_limit(
    limit: Any,
) -> None:
    coordinator = StaticCoordinator(
        resource=None
    )

    reranker = LocalBgeReranker(
        model="BAAI/bge-reranker-v2-m3",
        runtime=ready_runtime(),
        coordinator=coordinator,
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        reranker.rerank(
            question="سؤال",
            candidates=[],
            limit=limit,
        )

    assert (
        exc_info.value.code
        == "local_reranker_limit_invalid"
    )

    assert coordinator.model_ids == []


def test_local_bge_reranker_inference_failure_is_controlled() -> None:
    reranker = LocalBgeReranker(
        model="BAAI/bge-reranker-v2-m3",
        runtime=ready_runtime(),
        coordinator=StaticCoordinator(
            resources(
                tokenizer=RecordingTokenizer(),
                model=FailingModel(),
            )
        ),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        reranker.rerank(
            question="سؤال",
            candidates=[
                candidate(
                    point_id="a",
                    text="A",
                    score=0.1,
                    chunk_index=1,
                )
            ],
            limit=1,
        )

    assert (
        exc_info.value.code
        == "local_reranker_model_failed"
    )

    assert exc_info.value.message == (
        "Local reranker model inference failed."
    )

    assert (
        "private inference failure detail"
        not in exc_info.value.message
    )


def test_local_bge_reranker_load_failure_is_controlled(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    def fail_import(
        name: str,
    ) -> Any:
        raise RuntimeError(
            "private model loading detail"
        )

    monkeypatch.setattr(
        "app.processing.local_reranking.import_module",
        fail_import,
    )

    reranker = LocalBgeReranker(
        model="BAAI/bge-reranker-v2-m3",
        runtime=ready_runtime(),
        coordinator=LoadingCoordinator(),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        reranker.rerank(
            question="سؤال",
            candidates=[
                candidate(
                    point_id="a",
                    text="A",
                    score=0.1,
                    chunk_index=1,
                )
            ],
            limit=1,
        )

    assert (
        exc_info.value.code
        == "local_reranker_model_failed"
    )

    assert exc_info.value.message == (
        "Local reranker model failed to load."
    )


def test_hybrid_service_expands_candidates_then_reranks() -> None:
    question = "  السؤال الأصلي  "

    candidates = [
        candidate(
            point_id="a",
            text="A",
            score=0.1,
            chunk_index=1,
        ),
        candidate(
            point_id="b",
            text="B",
            score=0.2,
            chunk_index=2,
        ),
        candidate(
            point_id="c",
            text="C",
            score=0.3,
            chunk_index=3,
        ),
        candidate(
            point_id="d",
            text="D",
            score=0.4,
            chunk_index=4,
        ),
    ]

    class QueryEmbedder:
        def __init__(self) -> None:
            self.question: str | None = None

        def embed(
            self,
            received_question: str,
        ) -> list[float]:
            self.question = received_question
            return [0.1] * DENSE_VECTOR_SIZE

    class Retriever:
        def __init__(self) -> None:
            self.call: dict[str, Any] | None = None
            self.results = candidates

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[HybridLocalRetrievalResult]:
            self.call = kwargs
            return self.results

    class Reranker:
        def __init__(self) -> None:
            self.question: str | None = None
            self.candidates: (
                list[HybridLocalRetrievalResult]
                | None
            ) = None
            self.limit: int | None = None

        def rerank(
            self,
            *,
            question: str,
            candidates: list[
                HybridLocalRetrievalResult
            ],
            limit: int,
        ) -> list[HybridLocalRetrievalResult]:
            self.question = question
            self.candidates = candidates
            self.limit = limit

            return [
                candidates[3],
                candidates[1],
            ]

    embedder = QueryEmbedder()
    retriever = Retriever()
    reranker = Reranker()

    service = HybridLocalRetrievalService(
        settings=Settings(
            qdrant_hybrid_local_collection="local-k7",
        ),
        query_embedder=embedder,
        dense_retriever=retriever,
        reranker=reranker,
    )

    target = HybridLocalRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
    )

    results = service.retrieve(
        user_id=7,
        target=target,
        question=question,
        limit=2,
    )

    assert retriever.call is not None

    assert retriever.call["limit"] == 4
    assert retriever.call["question"] == question

    assert embedder.question == question

    assert reranker.question == question
    assert reranker.candidates == retriever.results
    assert reranker.limit == 4

    assert results[0] is candidates[3]
    assert results[1] is candidates[1]

    assert results[0].document_id == 12
    assert results[0].processing_run_id == 81
    assert (
        results[0].processing_profile
        is ProcessingProfile.HYBRID_LOCAL
    )


def test_hybrid_service_invalid_limit_fails_before_work() -> None:
    class MustNotRun:
        def __init__(self) -> None:
            self.called = False

        def embed(
            self,
            question: str,
        ) -> list[float]:
            self.called = True
            raise AssertionError

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.called = True
            raise AssertionError

        def rerank(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.called = True
            raise AssertionError

    worker = MustNotRun()

    service = HybridLocalRetrievalService(
        settings=Settings(),
        query_embedder=worker,
        dense_retriever=worker,
        reranker=worker,
    )

    target = HybridLocalRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        service.retrieve(
            user_id=7,
            target=target,
            question="سؤال",
            limit=0,
        )

    assert (
        exc_info.value.code
        == "hybrid_local_retrieval_limit_invalid"
    )

    assert worker.called is False


def test_k7_does_not_change_cloud_reranking_flow() -> None:
    cloud_candidates = [
        CloudRetrievalResult(
            point_id="cloud-a",
            retrieval_score=0.1,
            document_id=9,
            processing_run_id=99,
            processing_profile=ProcessingProfile.CLOUD,
            chunk_index=1,
            text="A",
            page=1,
            section=None,
            source="cloud.pdf",
        )
    ]

    class QueryEmbedder:
        def embed(
            self,
            question: str,
        ) -> list[float]:
            return [0.1] * DENSE_VECTOR_SIZE

    class Retriever:
        def __init__(self) -> None:
            self.limit: int | None = None

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[CloudRetrievalResult]:
            self.limit = kwargs["limit"]
            return cloud_candidates

    class Reranker:
        def __init__(self) -> None:
            self.limit: int | None = None
            self.received: (
                list[CloudRetrievalResult]
                | None
            ) = None

        def rerank(
            self,
            *,
            question: str,
            candidates: list[
                CloudRetrievalResult
            ],
            limit: int,
        ) -> list[CloudRetrievalResult]:
            self.limit = limit
            self.received = candidates
            return candidates

    retriever = Retriever()
    reranker = Reranker()

    service = CloudRetrievalService(
        settings=Settings(
            qdrant_cloud_collection="cloud-k7-regression",
        ),
        query_embedder=QueryEmbedder(),
        dense_retriever=retriever,
        reranker=reranker,
    )

    results = service.retrieve(
        user_id=7,
        target=CloudRetrievalTarget(
            document_id=9,
            processing_run_id=99,
            processing_profile=ProcessingProfile.CLOUD,
        ),
        question="cloud question",
        limit=3,
    )

    assert retriever.limit == 6
    assert reranker.limit == 3
    assert reranker.received is cloud_candidates
    assert results == cloud_candidates


def test_reranker_scores_bounded_batches_under_one_lease(monkeypatch):
    sizes = []
    coordinator = StaticCoordinator(object())
    reranker = LocalBgeReranker(model='local', runtime=ready_runtime(), coordinator=coordinator)
    def score(**kwargs):
        sizes.append(len(kwargs['candidates']))
        return [float(c.chunk_index) for c in kwargs['candidates']]
    monkeypatch.setattr(reranker, '_score_with_resources', score)
    results = reranker.rerank(question='سؤال', candidates=[candidate(point_id=str(i), text='نص', score=1, chunk_index=i) for i in range(7)], limit=3)
    assert [r.chunk_index for r in results] == [6,5,4]
    assert max(sizes) <= 4
    assert coordinator.model_ids == ['local']
