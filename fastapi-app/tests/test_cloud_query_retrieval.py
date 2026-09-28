import importlib
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
from app.processing.jina_provider import JinaProviderError


def _load_k2():
    try:
        from app.infrastructure.qdrant.retrieval import (
            QdrantCloudDenseRetriever,
        )
        from app.processing.cloud_query_embeddings import (
            CloudJinaQueryEmbedder,
        )
        from app.services.cloud_retrieval import (
            CloudRetrievalResult,
            CloudRetrievalService,
            CloudRetrievalTarget,
        )
    except ModuleNotFoundError as exc:
        pytest.fail(
            f"K2 implementation is not created yet: {exc}",
            pytrace=False,
        )

    return (
        CloudJinaQueryEmbedder,
        QdrantCloudDenseRetriever,
        CloudRetrievalResult,
        CloudRetrievalService,
        CloudRetrievalTarget,
    )


class StaticProvider:
    def __init__(self, vectors: Any) -> None:
        self.vectors = vectors
        self.calls: list[list[str]] = []

    def embed(self, texts: list[str]) -> Any:
        self.calls.append(texts)
        return self.vectors


class FailIfCalledReranker:
    def rerank(
        self,
        **kwargs: Any,
    ) -> list[Any]:
        raise AssertionError(
            "Reranker must not be called."
        )


def test_cloud_query_embedder_uses_retrieval_query_and_1024(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    (
        CloudJinaQueryEmbedder,
        _,
        _,
        _,
        _,
    ) = _load_k2()

    module = importlib.import_module(
        "app.processing.cloud_query_embeddings"
    )

    captured: dict[str, Any] = {}

    class CapturingProvider:
        def __init__(
            self,
            *,
            api_key: str,
            model: str,
            task: str,
            dimensions: int,
        ) -> None:
            captured["api_key"] = api_key
            captured["model"] = model
            captured["task"] = task
            captured["dimensions"] = dimensions

        def embed(self, texts: list[str]) -> list[list[float]]:
            captured["texts"] = texts
            return [[0.1] * DENSE_VECTOR_SIZE]

    monkeypatch.setattr(
        module,
        "JinaEmbeddingProvider",
        CapturingProvider,
    )

    embedder = CloudJinaQueryEmbedder(
        api_key="secret",
        model="jina-embeddings-v3",
    )

    vector = embedder.embed("  ما شروط فسخ العقد؟  ")

    assert captured["task"] == "retrieval.query"
    assert captured["dimensions"] == 1024
    assert captured["texts"] == ["ما شروط فسخ العقد؟"]
    assert len(vector) == 1024


def test_cloud_query_embedder_rejects_blank_before_provider_call() -> None:
    (
        CloudJinaQueryEmbedder,
        _,
        _,
        _,
        _,
    ) = _load_k2()

    provider = StaticProvider(
        [[0.1] * DENSE_VECTOR_SIZE],
    )

    embedder = CloudJinaQueryEmbedder(
        api_key="secret",
        model="jina-embeddings-v3",
        provider=provider,
    )

    with pytest.raises(ApplicationException) as exc_info:
        embedder.embed("   ")

    assert exc_info.value.code == "cloud_query_invalid"
    assert provider.calls == []


@pytest.mark.parametrize(
    "invalid_vectors",
    [
        [],
        [[0.1] * 512],
        [
            [0.1] * DENSE_VECTOR_SIZE,
            [0.2] * DENSE_VECTOR_SIZE,
        ],
        [None],
    ],
    ids=[
        "missing-vector",
        "wrong-dimension",
        "extra-vector",
        "malformed-vector",
    ],
)
def test_cloud_query_embedder_rejects_invalid_provider_result(
    invalid_vectors: Any,
) -> None:
    (
        CloudJinaQueryEmbedder,
        _,
        _,
        _,
        _,
    ) = _load_k2()

    embedder = CloudJinaQueryEmbedder(
        api_key="secret",
        model="jina-embeddings-v3",
        provider=StaticProvider(invalid_vectors),
    )

    with pytest.raises(ApplicationException) as exc_info:
        embedder.embed("سؤال صالح")

    assert (
        exc_info.value.code
        == "cloud_query_embedding_result_invalid"
    )


def test_cloud_query_embedder_preserves_retryable_jina_semantics() -> None:
    (
        CloudJinaQueryEmbedder,
        _,
        _,
        _,
        _,
    ) = _load_k2()

    class RetryProvider:
        def __init__(self) -> None:
            self.attempts = 0

        def embed(self, texts: list[str]) -> list[list[float]]:
            self.attempts += 1

            if self.attempts == 1:
                raise JinaProviderError(retryable=True)

            return [[0.1] * DENSE_VECTOR_SIZE]

    provider = RetryProvider()
    sleeps: list[float] = []

    embedder = CloudJinaQueryEmbedder(
        api_key="secret",
        model="jina-embeddings-v3",
        max_retries=1,
        rate_limit_retry_wait=0.25,
        sleeper=sleeps.append,
        provider=provider,
    )

    vector = embedder.embed("سؤال")

    assert len(vector) == DENSE_VECTOR_SIZE
    assert provider.attempts == 2
    assert sleeps == [0.25]


def test_cloud_query_embedder_maps_provider_failure() -> None:
    (
        CloudJinaQueryEmbedder,
        _,
        _,
        _,
        _,
    ) = _load_k2()

    class FailingProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            raise JinaProviderError(retryable=False)

    embedder = CloudJinaQueryEmbedder(
        api_key="secret",
        model="jina-embeddings-v3",
        provider=FailingProvider(),
    )

    with pytest.raises(ApplicationException) as exc_info:
        embedder.embed("سؤال")

    assert (
        exc_info.value.code
        == "cloud_query_embedding_provider_failed"
    )


def test_cloud_retrieval_service_resolves_cloud_collection() -> None:
    (
        _,
        _,
        _,
        CloudRetrievalService,
        CloudRetrievalTarget,
    ) = _load_k2()

    class QueryEmbedder:
        def __init__(self) -> None:
            self.question: str | None = None

        def embed(self, question: str) -> list[float]:
            self.question = question
            return [0.1] * DENSE_VECTOR_SIZE

    class DenseRetriever:
        def __init__(self) -> None:
            self.call: dict[str, Any] | None = None

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.call = kwargs
            return []

    query_embedder = QueryEmbedder()
    dense_retriever = DenseRetriever()

    service = CloudRetrievalService(
        settings=Settings(
            qdrant_cloud_collection="cloud-k2",
            qdrant_hybrid_local_collection="local-k2",
        ),
        query_embedder=query_embedder,
        dense_retriever=dense_retriever,
        reranker=FailIfCalledReranker(),
    )

    target = CloudRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=ProcessingProfile.CLOUD,
    )

    results = service.retrieve(
        user_id=7,
        target=target,
        question="ما شروط فسخ العقد؟",
        limit=6,
    )

    assert results == []
    assert (
        query_embedder.question
        == "ما شروط فسخ العقد؟"
    )

    assert dense_retriever.call is not None

    assert (
        dense_retriever.call["collection_name"]
        == "cloud-k2"
    )
    assert dense_retriever.call["user_id"] == 7
    assert dense_retriever.call["target"] == target

    # K6 expands the RRF candidate pool before reranking.
    assert dense_retriever.call["limit"] == 12

    assert (
        len(
            dense_retriever.call[
                "query_vector"
            ]
        )
        == DENSE_VECTOR_SIZE
    )


def test_cloud_retrieval_service_rejects_non_cloud_target() -> None:
    (
        _,
        _,
        _,
        CloudRetrievalService,
        CloudRetrievalTarget,
    ) = _load_k2()

    class QueryEmbedder:
        def __init__(self) -> None:
            self.called = False

        def embed(
            self,
            question: str,
        ) -> list[float]:
            self.called = True
            return [0.1] * DENSE_VECTOR_SIZE

    class DenseRetriever:
        def __init__(self) -> None:
            self.called = False

        def retrieve(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.called = True
            return []

    class Reranker:
        def __init__(self) -> None:
            self.called = False

        def rerank(
            self,
            **kwargs: Any,
        ) -> list[Any]:
            self.called = True
            return []

    query_embedder = QueryEmbedder()
    dense_retriever = DenseRetriever()
    reranker = Reranker()

    service = CloudRetrievalService(
        settings=Settings(),
        query_embedder=query_embedder,
        dense_retriever=dense_retriever,
        reranker=reranker,
    )

    target = CloudRetrievalTarget(
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
        == "cloud_retrieval_target_invalid"
    )

    assert query_embedder.called is False
    assert dense_retriever.called is False
    assert reranker.called is False


@pytest.mark.parametrize(
    ("field", "value"),
    [
        ("user_id", 99),
        ("document_id", 99),
        ("processing_run_id", 99),
        ("processing_profile", "hybrid_local"),
    ],
)
def test_qdrant_cloud_dense_retriever_fails_closed_on_scope_mismatch(
    field: str,
    value: Any,
) -> None:
    (
        _,
        QdrantCloudDenseRetriever,
        _,
        _,
        CloudRetrievalTarget,
    ) = _load_k2()

    payload = {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
        "processing_profile": "cloud",
        "chunk_index": 3,
        "text": "نص",
        "page": None,
        "section": None,
        "source": "doc.pdf",
    }
    payload[field] = value

    point = SimpleNamespace(
        id="point-1",
        score=0.8,
        payload=payload,
    )

    class FakeClient:
        def query_points(self, **kwargs: Any) -> Any:
            return SimpleNamespace(points=[point])

    retriever = QdrantCloudDenseRetriever(
        client=FakeClient(),
    )

    target = CloudRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=ProcessingProfile.CLOUD,
    )

    with pytest.raises(ApplicationException) as exc_info:
        retriever.retrieve(
            collection_name="cloud-k2",
            user_id=7,
            target=target,
            query_vector=[0.1] * DENSE_VECTOR_SIZE,
            limit=6,
        )

    assert (
        exc_info.value.code
        == "cloud_retrieval_result_scope_invalid"
    )


def test_qdrant_cloud_dense_retriever_isolates_exact_scope_in_memory() -> None:
    from qdrant_client import QdrantClient, models

    (
        _,
        QdrantCloudDenseRetriever,
        _,
        _,
        CloudRetrievalTarget,
    ) = _load_k2()

    client = QdrantClient(":memory:")
    collection_name = "k2_cloud_scope_isolation"

    client.create_collection(
        collection_name=collection_name,
        vectors_config={
            DENSE_VECTOR_NAME: models.VectorParams(
                size=DENSE_VECTOR_SIZE,
                distance=models.Distance.COSINE,
            ),
        },
    )

    vector = [0.1] * DENSE_VECTOR_SIZE

    def point(
        point_id: int,
        *,
        user_id: int = 7,
        document_id: int = 12,
        processing_run_id: int = 81,
        processing_profile: str = "cloud",
    ) -> models.PointStruct:
        return models.PointStruct(
            id=point_id,
            vector={
                DENSE_VECTOR_NAME: vector,
            },
            payload={
                "user_id": user_id,
                "document_id": document_id,
                "processing_run_id": processing_run_id,
                "processing_profile": processing_profile,
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
            point(4, processing_run_id=99),
            point(5, processing_profile="hybrid_local"),
        ],
        wait=True,
    )

    retriever = QdrantCloudDenseRetriever(client=client)

    target = CloudRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=ProcessingProfile.CLOUD,
    )

    results = retriever.retrieve(
        collection_name=collection_name,
        user_id=7,
        target=target,
        query_vector=vector,
        limit=10,
    )

    assert [result.point_id for result in results] == ["1"]
    assert results[0].document_id == 12
    assert results[0].processing_run_id == 81
    assert results[0].processing_profile is ProcessingProfile.CLOUD

    client.close()
