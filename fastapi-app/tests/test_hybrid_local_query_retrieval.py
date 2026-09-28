from collections.abc import Callable, Iterator
from contextlib import contextmanager
from types import SimpleNamespace
from typing import Any

import pytest

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.schema import (
    DENSE_VECTOR_NAME,
    DENSE_VECTOR_SIZE,
)
from app.processing.base import ProcessingProfile
from app.processing.chunks import NormalizedChunk
from app.processing.local_embeddings import LocalBgeM3Embedder
from app.processing.local_query_embeddings import (
    LocalBgeM3QueryEmbedder,
)
from app.runtime.models import (
    LocalRuntimeSnapshot,
    ResourceSnapshot,
    RuntimeBackend,
    RuntimeDtype,
    RuntimeProbeStatus,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
    HybridLocalRetrievalService,
    HybridLocalRetrievalTarget,
)
from app.infrastructure.qdrant.retrieval import (
    QdrantHybridLocalDenseRetriever,
)


class StaticTextEmbedder:
    def __init__(
        self,
        vectors: Any,
    ) -> None:
        self.vectors = vectors
        self.calls: list[list[str]] = []

    def embed_texts(
        self,
        texts: list[str],
    ) -> Any:
        self.calls.append(texts)
        return self.vectors


def test_local_query_returns_single_1024_vector() -> None:
    primitive = StaticTextEmbedder(
        [[0.1] * DENSE_VECTOR_SIZE]
    )

    embedder = LocalBgeM3QueryEmbedder(
        text_embedder=primitive,
    )

    vector = embedder.embed(
        "  ما شروط فسخ العقد؟  "
    )

    assert primitive.calls == [
        ["ما شروط فسخ العقد؟"]
    ]
    assert len(vector) == 1024


def test_local_query_rejects_blank_before_model_call() -> None:
    primitive = StaticTextEmbedder(
        [[0.1] * DENSE_VECTOR_SIZE]
    )

    embedder = LocalBgeM3QueryEmbedder(
        text_embedder=primitive,
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        embedder.embed("   ")

    assert exc_info.value.code == "local_query_invalid"
    assert primitive.calls == []


@pytest.mark.parametrize(
    "vectors",
    [
        [],
        [[0.1] * 512],
        [
            [0.1] * 1024,
            [0.2] * 1024,
        ],
        [None],
    ],
)
def test_local_query_rejects_malformed_result(
    vectors: Any,
) -> None:
    embedder = LocalBgeM3QueryEmbedder(
        text_embedder=StaticTextEmbedder(
            vectors
        ),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        embedder.embed("سؤال صالح")

    assert (
        exc_info.value.code
        == "local_query_embedding_result_invalid"
    )


def test_local_query_rejects_non_numeric_vector() -> None:
    embedder = LocalBgeM3QueryEmbedder(
        text_embedder=StaticTextEmbedder(
            [["x"] * DENSE_VECTOR_SIZE]
        ),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        embedder.embed("سؤال صالح")

    assert (
        exc_info.value.code
        == "local_query_embedding_result_invalid"
    )


def test_local_query_preserves_local_embedding_failure() -> None:
    class FailingPrimitive:
        def embed_texts(
            self,
            texts: list[str],
        ) -> list[list[float]]:
            raise ApplicationException(
                code="local_embedding_model_failed",
                message="Local model failed.",
            )

    embedder = LocalBgeM3QueryEmbedder(
        text_embedder=FailingPrimitive(),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        embedder.embed("سؤال")

    assert (
        exc_info.value.code
        == "local_embedding_model_failed"
    )


def test_local_embedder_rejects_unavailable_runtime() -> None:
    runtime = LocalRuntimeSnapshot(
        ready=False,
        requested_device="auto",
        selected_backend=None,
        selected_dtype=None,
        probe_status=RuntimeProbeStatus.FAILED,
        failure_reason="runtime unavailable",
        resources=ResourceSnapshot(),
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        LocalBgeM3Embedder(
            model="BAAI/bge-m3",
            runtime=runtime,
            coordinator=SimpleNamespace(),
        )

    assert (
        exc_info.value.code
        == "local_embedding_runtime_unavailable"
    )


class FakeInputTensor:
    def to(
        self,
        device: str,
    ) -> "FakeInputTensor":
        return self


class FakeDenseTensor:
    def __init__(
        self,
        vectors: list[list[float]],
    ) -> None:
        self._vectors = vectors

    def __getitem__(
        self,
        key: Any,
    ) -> "FakeDenseTensor":
        assert key == (slice(None), 0)
        return self

    def detach(self) -> "FakeDenseTensor":
        return self

    def cpu(self) -> "FakeDenseTensor":
        return self

    def float(self) -> "FakeDenseTensor":
        return self

    def tolist(
        self,
    ) -> list[list[float]]:
        return self._vectors


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


class FakeCoordinator:
    def __init__(self) -> None:
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

        resource = loader()

        yield SimpleNamespace(
            resource=resource
        )


class NeverCalledReranker:
    def __init__(self) -> None:
        self.called = False

    def rerank(
        self,
        **kwargs: Any,
    ) -> list[Any]:
        self.called = True
        raise AssertionError(
            "Reranker must not be called."
        )


def test_query_and_document_use_same_bge_m3_primitive(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    received_texts: list[list[str]] = []

    vector = [3.0] * DENSE_VECTOR_SIZE

    class FakeTokenizer:
        @classmethod
        def from_pretrained(
            cls,
            model: str,
        ) -> "FakeTokenizer":
            assert model == "BAAI/bge-m3"
            return cls()

        def __call__(
            self,
            texts: list[str],
            **kwargs: Any,
        ) -> dict[str, Any]:
            received_texts.append(texts)

            assert kwargs == {
                "padding": True,
                "truncation": True,
                "return_tensors": "pt",
            }

            return {
                "input_ids": FakeInputTensor(),
                "attention_mask": FakeInputTensor(),
            }

    class FakeModel:
        @classmethod
        def from_pretrained(
            cls,
            model: str,
            **kwargs: Any,
        ) -> "FakeModel":
            assert model == "BAAI/bge-m3"
            return cls()

        def to(
            self,
            device: str,
        ) -> "FakeModel":
            assert device == "cpu"
            return self

        def eval(self) -> None:
            return None

        def __call__(
            self,
            **inputs: Any,
        ) -> Any:
            return SimpleNamespace(
                last_hidden_state=FakeDenseTensor(
                    [vector]
                )
            )

    fake_torch = SimpleNamespace(
        float16="fp16",
        float32="fp32",
        inference_mode=lambda: FakeInferenceMode(),
    )

    fake_transformers = SimpleNamespace(
        AutoTokenizer=FakeTokenizer,
        AutoModel=FakeModel,
    )

    def fake_import_module(
        name: str,
    ) -> Any:
        if name == "torch":
            return fake_torch

        if name == "transformers":
            return fake_transformers

        raise AssertionError(name)

    monkeypatch.setattr(
        "app.processing.local_embeddings.import_module",
        fake_import_module,
    )

    runtime = LocalRuntimeSnapshot(
        ready=True,
        requested_device="auto",
        selected_backend=RuntimeBackend.CPU,
        selected_dtype=RuntimeDtype.FP32,
        probe_status=RuntimeProbeStatus.PASSED,
        failure_reason=None,
        resources=ResourceSnapshot(),
    )

    coordinator = FakeCoordinator()

    primitive = LocalBgeM3Embedder(
        model="BAAI/bge-m3",
        runtime=runtime,
        coordinator=coordinator,
    )

    document_vector = primitive.embed(
        [
            NormalizedChunk(
                text="same text"
            )
        ]
    )[0]

    query_embedder = LocalBgeM3QueryEmbedder(
        text_embedder=primitive,
    )

    query_vector = query_embedder.embed(
        "same text"
    )

    assert document_vector == vector
    assert query_vector == vector

    assert received_texts == [
        ["same text"],
        ["same text"],
    ]

    assert coordinator.model_ids == [
        "BAAI/bge-m3",
        "BAAI/bge-m3",
    ]


def test_hybrid_service_resolves_local_collection() -> None:
    class QueryEmbedder:
        def embed(
            self,
            question: str,
        ) -> list[float]:
            return [0.1] * DENSE_VECTOR_SIZE

    class Retriever:
        def __init__(self) -> None:
            self.call: dict[str, Any] | None = None

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.call = kwargs
            return []

    retriever = Retriever()

    service = HybridLocalRetrievalService(
        settings=Settings(
            qdrant_cloud_collection="cloud-k3",
            qdrant_hybrid_local_collection="local-k3",
        ),
        query_embedder=QueryEmbedder(),
        dense_retriever=retriever,
        reranker=NeverCalledReranker(),
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
        question="سؤال",
        limit=6,
    )

    assert results == []

    assert retriever.call is not None
    assert (
        retriever.call["collection_name"]
        == "local-k3"
    )
    assert retriever.call["user_id"] == 7
    assert retriever.call["limit"] == 12
    assert (
        len(retriever.call["query_vector"])
        == DENSE_VECTOR_SIZE
    )


def test_hybrid_service_rejects_cloud_target_before_work() -> None:
    class QueryEmbedder:
        def __init__(self) -> None:
            self.called = False

        def embed(
            self,
            question: str,
        ) -> list[float]:
            self.called = True
            return [0.1] * DENSE_VECTOR_SIZE

    class Retriever:
        def __init__(self) -> None:
            self.called = False

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.called = True
            return []

    embedder = QueryEmbedder()
    retriever = Retriever()

    service = HybridLocalRetrievalService(
        settings=Settings(),
        query_embedder=embedder,
        dense_retriever=retriever,
        reranker=NeverCalledReranker(),
    )

    target = HybridLocalRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=ProcessingProfile.CLOUD,
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        service.retrieve(
            user_id=7,
            target=target,
            question="سؤال",
            limit=6,
        )

    assert (
        exc_info.value.code
        == "hybrid_local_retrieval_target_invalid"
    )

    assert embedder.called is False
    assert retriever.called is False


def test_local_failure_has_no_retrieval_fallback() -> None:
    class FailingEmbedder:
        def embed(
            self,
            question: str,
        ) -> list[float]:
            raise ApplicationException(
                code="local_embedding_model_failed",
                message="Local model failed.",
            )

    class Retriever:
        def __init__(self) -> None:
            self.called = False

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.called = True
            return []

    retriever = Retriever()

    service = HybridLocalRetrievalService(
        settings=Settings(
            qdrant_hybrid_local_collection="local-k3",
        ),
        query_embedder=FailingEmbedder(),
        dense_retriever=retriever,
        reranker=NeverCalledReranker(),
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
            limit=6,
        )

    assert (
        exc_info.value.code
        == "local_embedding_model_failed"
    )
    assert retriever.called is False


def test_qdrant_hybrid_retrieval_contract() -> None:
    point = SimpleNamespace(
        id="point-1",
        score=0.91,
        payload={
            "user_id": 7,
            "document_id": 12,
            "processing_run_id": 81,
            "processing_profile": "hybrid_local",
            "chunk_index": 3,
            "text": "نص العقد",
            "page": 2,
            "section": "العقد",
            "source": "contract.pdf",
        },
    )

    class FakeClient:
        def __init__(self) -> None:
            self.call: dict[str, Any] | None = None

        def query_points(
            self,
            **kwargs: Any,
        ) -> Any:
            self.call = kwargs

            return SimpleNamespace(
                points=[point]
            )

    client = FakeClient()

    retriever = QdrantHybridLocalDenseRetriever(
        client=client
    )

    target = HybridLocalRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
    )

    vector = [0.1] * DENSE_VECTOR_SIZE

    results = retriever.retrieve(
        collection_name="local-k3",
        user_id=7,
        target=target,
        query_vector=vector,
        limit=6,
    )

    assert client.call is not None

    assert client.call["query"] == vector
    assert (
        client.call["using"]
        == DENSE_VECTOR_NAME
    )
    assert client.call["limit"] == 6
    assert client.call["with_vectors"] is False

    assert set(
        client.call["with_payload"]
    ) == {
        "user_id",
        "document_id",
        "processing_run_id",
        "processing_profile",
        "chunk_index",
        "text",
        "page",
        "section",
        "source",
    }

    conditions = {
        condition.key: condition.match.value
        for condition
        in client.call["query_filter"].must
    }

    assert conditions == {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
        "processing_profile": "hybrid_local",
    }

    assert len(results) == 1
    assert isinstance(
        results[0],
        HybridLocalRetrievalResult,
    )

    assert results[0].point_id == "point-1"
    assert results[0].document_id == 12
    assert results[0].processing_run_id == 81
    assert (
        results[0].processing_profile
        is ProcessingProfile.HYBRID_LOCAL
    )


@pytest.mark.parametrize(
    ("field", "value"),
    [
        ("user_id", 99),
        ("document_id", 99),
        ("processing_run_id", 99),
        ("processing_profile", "cloud"),
    ],
)
def test_qdrant_hybrid_result_scope_is_fail_closed(
    field: str,
    value: Any,
) -> None:
    payload = {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
        "processing_profile": "hybrid_local",
        "chunk_index": 3,
        "text": "نص",
        "page": None,
        "section": None,
        "source": "doc.pdf",
    }

    payload[field] = value

    class FakeClient:
        def query_points(
            self,
            **kwargs: Any,
        ) -> Any:
            return SimpleNamespace(
                points=[
                    SimpleNamespace(
                        id="point-1",
                        score=0.8,
                        payload=payload,
                    )
                ]
            )

    retriever = QdrantHybridLocalDenseRetriever(
        client=FakeClient(),
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
        retriever.retrieve(
            collection_name="local-k3",
            user_id=7,
            target=target,
            query_vector=(
                [0.1] * DENSE_VECTOR_SIZE
            ),
            limit=6,
        )

    assert (
        exc_info.value.code
        == (
            "hybrid_local_retrieval_result_"
            "scope_invalid"
        )
    )


def test_qdrant_hybrid_isolates_exact_scope_in_memory() -> None:
    from qdrant_client import (
        QdrantClient,
        models,
    )

    client = QdrantClient(":memory:")

    collection_name = (
        "k3_hybrid_scope_isolation"
    )

    client.create_collection(
        collection_name=collection_name,
        vectors_config={
            DENSE_VECTOR_NAME: models.VectorParams(
                size=DENSE_VECTOR_SIZE,
                distance=models.Distance.COSINE,
            )
        },
    )

    vector = [0.1] * DENSE_VECTOR_SIZE

    def point(
        point_id: int,
        *,
        user_id: int = 7,
        document_id: int = 12,
        processing_run_id: int = 81,
        processing_profile: str = "hybrid_local",
    ) -> models.PointStruct:
        return models.PointStruct(
            id=point_id,
            vector={
                DENSE_VECTOR_NAME: vector
            },
            payload={
                "user_id": user_id,
                "document_id": document_id,
                "processing_run_id": (
                    processing_run_id
                ),
                "processing_profile": (
                    processing_profile
                ),
                "chunk_index": point_id,
                "text": f"chunk-{point_id}",
                "page": None,
                "section": None,
                "source": "document.pdf",
            },
        )

    client.upsert(
        collection_name=collection_name,
        points=[
            point(1),
            point(2, user_id=99),
            point(3, document_id=99),
            point(
                4,
                processing_run_id=99,
            ),
            point(
                5,
                processing_profile="cloud",
            ),
        ],
        wait=True,
    )

    retriever = QdrantHybridLocalDenseRetriever(
        client=client
    )

    target = HybridLocalRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
    )

    results = retriever.retrieve(
        collection_name=collection_name,
        user_id=7,
        target=target,
        query_vector=vector,
        limit=10,
    )

    assert [
        result.point_id
        for result in results
    ] == ["1"]

    assert (
        results[0].processing_profile
        is ProcessingProfile.HYBRID_LOCAL
    )

    client.close()


def test_many_targets_embed_and_rerank_once_preserving_per_document_top_k():
    from dataclasses import replace
    calls = []
    class Embedder:
        def embed(self, question):
            calls.append('embed')
            return [0.1] * 1024
    class Retriever:
        def retrieve(self, **kwargs):
            t = kwargs['target']
            assert kwargs['limit'] == 4
            assert kwargs['user_id'] == 7
            return [HybridLocalRetrievalResult(
                point_id=f'{t.document_id}-{i}', retrieval_score=4-i,
                document_id=t.document_id, processing_run_id=t.processing_run_id,
                processing_profile=t.processing_profile, chunk_index=i,
                text=str(i), page=None, section=None, source='doc',
            ) for i in range(4)]
    class Reranker:
        def rerank(self, *, question, candidates, limit):
            calls.append('rerank')
            return sorted([replace(c, reranker_score=float(c.text)) for c in candidates],
                          key=lambda c: c.reranker_score, reverse=True)[:limit]
    service = HybridLocalRetrievalService(settings=Settings(), query_embedder=Embedder(),
        dense_retriever=Retriever(), reranker=Reranker())
    outcomes = service.retrieve_many_observed(user_id=7, question='سؤال', limit=2,
        targets=[HybridLocalRetrievalTarget(i, i+10, ProcessingProfile.HYBRID_LOCAL) for i in [1,2]])
    assert [[r.point_id for r in o.results] for o in outcomes] == [['1-3','1-2'], ['2-3','2-2']]
    assert calls == ['embed', 'rerank']
