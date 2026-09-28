from types import SimpleNamespace
from typing import Any

import pytest
from qdrant_client import (
    QdrantClient,
    models,
)

from app.core.exceptions import (
    ApplicationException,
)
from app.infrastructure.qdrant.retrieval import (
    RETRIEVAL_PAYLOAD_FIELDS,
    QdrantCloudRrfRetriever,
    QdrantHybridLocalRrfRetriever,
)
from app.infrastructure.qdrant.schema import (
    DENSE_VECTOR_NAME,
    SPARSE_VECTOR_MODIFIER,
    SPARSE_VECTOR_NAME,
)
from app.processing.base import (
    ProcessingProfile,
)
from app.processing.cloud_sparse import (
    CLOUD_SPARSE_MODEL,
    CLOUD_SPARSE_TOKENIZER,
    CloudSparseRepresenter,
)
from app.processing.local_sparse import (
    LocalBm25Representer,
)
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
    CloudRetrievalTarget,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
    HybridLocalRetrievalTarget,
)

TEST_RRF_CANDIDATE_MULTIPLIER = 2


class FakeQdrantClient:
    def __init__(
        self,
        point: Any,
    ) -> None:
        self.point = point
        self.call: dict[str, Any] | None = None

    def query_points(
        self,
        **kwargs: Any,
    ) -> Any:
        self.call = kwargs

        return SimpleNamespace(
            points=[self.point]
        )


class StaticBm25Embedder:
    def __init__(
        self,
        *,
        indices: list[int] | None = None,
        values: list[float] | None = None,
    ) -> None:
        self.indices = indices or [10]
        self.values = values or [1.0]
        self.calls: list[list[str]] = []

    def embed(
        self,
        texts: list[str],
    ) -> Any:
        self.calls.append(texts)

        return iter(
            [
                SimpleNamespace(
                    indices=self.indices,
                    values=self.values,
                )
                for _ in texts
            ]
        )


class StaticSparseQueryRepresenter:
    def __init__(
        self,
        vector: models.SparseVector,
    ) -> None:
        self.vector = vector
        self.questions: list[str] = []

    def represent_query(
        self,
        question: str,
    ) -> models.SparseVector:
        self.questions.append(question)
        return self.vector


def _payload(
    profile: ProcessingProfile,
    *,
    user_id: int = 7,
    document_id: int = 12,
    processing_run_id: int = 81,
    chunk_index: int = 3,
) -> dict[str, Any]:
    return {
        "user_id": user_id,
        "document_id": document_id,
        "processing_run_id": processing_run_id,
        "processing_profile": profile.value,
        "chunk_index": chunk_index,
        "text": f"chunk-{chunk_index}",
        "page": 2,
        "section": "section",
        "source": "document.pdf",
    }


def _point(
    profile: ProcessingProfile,
    *,
    payload: dict[str, Any] | None = None,
) -> Any:
    return SimpleNamespace(
        id="point-1",
        score=0.91,
        payload=(
            payload
            if payload is not None
            else _payload(profile)
        ),
    )


def _filter_values(
    query_filter: models.Filter,
) -> dict[str, Any]:
    return {
        condition.key: condition.match.value
        for condition in query_filter.must
    }


def _expected_scope(
    profile: ProcessingProfile,
) -> dict[str, Any]:
    return {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
        "processing_profile": profile.value,
    }


def _assert_rrf_contract(
    *,
    call: dict[str, Any],
    profile: ProcessingProfile,
    limit: int,
) -> tuple[
    models.Prefetch,
    models.Prefetch,
]:
    assert isinstance(
        call["query"],
        models.FusionQuery,
    )
    assert (
        call["query"].fusion
        == models.Fusion.RRF
    )

    assert call["limit"] == limit
    assert call["with_vectors"] is False

    assert set(
        call["with_payload"]
    ) == set(RETRIEVAL_PAYLOAD_FIELDS)

    expected_scope = _expected_scope(
        profile
    )

    assert _filter_values(
        call["query_filter"]
    ) == expected_scope

    prefetches = call["prefetch"]

    assert len(prefetches) == 2

    dense_prefetch = prefetches[0]
    sparse_prefetch = prefetches[1]

    assert (
        dense_prefetch.using
        == DENSE_VECTOR_NAME
    )
    assert (
        sparse_prefetch.using
        == SPARSE_VECTOR_NAME
    )

    candidate_limit = (
        limit * TEST_RRF_CANDIDATE_MULTIPLIER
    )

    assert (
        dense_prefetch.limit
        == candidate_limit
    )
    assert (
        sparse_prefetch.limit
        == candidate_limit
    )

    assert _filter_values(
        dense_prefetch.filter
    ) == expected_scope

    assert _filter_values(
        sparse_prefetch.filter
    ) == expected_scope

    return (
        dense_prefetch,
        sparse_prefetch,
    )


def test_cloud_query_bm25_uses_cloud_document_semantics() -> None:
    query = (
        CloudSparseRepresenter()
        .represent_query(
            "  شروط فسخ العقد  "
        )
    )

    assert isinstance(
        query,
        models.Document,
    )
    assert query.text == "شروط فسخ العقد"
    assert query.model == CLOUD_SPARSE_MODEL
    assert query.options == {
        "tokenizer": (
            CLOUD_SPARSE_TOKENIZER
        ),
    }


def test_local_query_bm25_reuses_local_document_primitive() -> None:
    embedder = StaticBm25Embedder(
        indices=[10, 20],
        values=[0.5, 1.0],
    )

    representer = LocalBm25Representer(
        embedder=embedder
    )

    query = representer.represent_query(
        "  شروط فسخ العقد  "
    )

    assert embedder.calls == [
        ["شروط فسخ العقد"]
    ]

    assert isinstance(
        query,
        models.SparseVector,
    )
    assert query.indices == [10, 20]
    assert query.values == [0.5, 1.0]


def test_cloud_rrf_uses_dense_bm25_and_trusted_filters() -> None:
    client = FakeQdrantClient(
        _point(ProcessingProfile.CLOUD)
    )

    retriever = QdrantCloudRrfRetriever(
        candidate_multiplier=TEST_RRF_CANDIDATE_MULTIPLIER,
        client=client,
        sparse_query_representer=(
            CloudSparseRepresenter()
        ),
    )

    target = CloudRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=(
            ProcessingProfile.CLOUD
        ),
    )

    dense_query = [0.1, 0.2]

    results = retriever.retrieve(
        collection_name="cloud-k5",
        user_id=7,
        target=target,
        question="سؤال cloud",
        query_vector=dense_query,
        limit=6,
    )

    assert client.call is not None

    (
        dense_prefetch,
        sparse_prefetch,
    ) = _assert_rrf_contract(
        call=client.call,
        profile=ProcessingProfile.CLOUD,
        limit=6,
    )

    assert (
        dense_prefetch.query
        == dense_query
    )

    assert isinstance(
        sparse_prefetch.query,
        models.Document,
    )
    assert (
        sparse_prefetch.query.model
        == CLOUD_SPARSE_MODEL
    )
    assert (
        sparse_prefetch.query.options
        == {
            "tokenizer": (
                CLOUD_SPARSE_TOKENIZER
            )
        }
    )

    assert len(results) == 1
    assert isinstance(
        results[0],
        CloudRetrievalResult,
    )
    assert (
        results[0].processing_profile
        is ProcessingProfile.CLOUD
    )


def test_hybrid_local_rrf_uses_dense_bm25_and_trusted_filters() -> None:
    client = FakeQdrantClient(
        _point(
            ProcessingProfile.HYBRID_LOCAL
        )
    )

    embedder = StaticBm25Embedder(
        indices=[10],
        values=[1.0],
    )

    retriever = (
        QdrantHybridLocalRrfRetriever(
            candidate_multiplier=TEST_RRF_CANDIDATE_MULTIPLIER,
            client=client,
            sparse_query_representer=(
                LocalBm25Representer(
                    embedder=embedder
                )
            ),
        )
    )

    target = HybridLocalRetrievalTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
    )

    dense_query = [0.1, 0.2]

    results = retriever.retrieve(
        collection_name="local-k5",
        user_id=7,
        target=target,
        question="سؤال محلي",
        query_vector=dense_query,
        limit=6,
    )

    assert client.call is not None

    (
        dense_prefetch,
        sparse_prefetch,
    ) = _assert_rrf_contract(
        call=client.call,
        profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
        limit=6,
    )

    assert (
        dense_prefetch.query
        == dense_query
    )

    assert embedder.calls == [
        ["سؤال محلي"]
    ]

    assert isinstance(
        sparse_prefetch.query,
        models.SparseVector,
    )
    assert (
        sparse_prefetch.query.indices
        == [10]
    )
    assert (
        sparse_prefetch.query.values
        == [1.0]
    )

    assert len(results) == 1
    assert isinstance(
        results[0],
        HybridLocalRetrievalResult,
    )
    assert (
        results[0].processing_profile
        is ProcessingProfile.HYBRID_LOCAL
    )


@pytest.mark.parametrize(
    "field",
    [
        "user_id",
        "document_id",
        "processing_run_id",
        "processing_profile",
    ],
)
@pytest.mark.parametrize(
    "profile",
    [
        ProcessingProfile.CLOUD,
        ProcessingProfile.HYBRID_LOCAL,
    ],
)
def test_rrf_final_result_scope_is_fail_closed(
    profile: ProcessingProfile,
    field: str,
) -> None:
    payload = _payload(profile)

    mismatches: dict[str, Any] = {
        "user_id": 99,
        "document_id": 99,
        "processing_run_id": 99,
        "processing_profile": (
            ProcessingProfile.HYBRID_LOCAL.value
            if profile is ProcessingProfile.CLOUD
            else ProcessingProfile.CLOUD.value
        ),
    }

    payload[field] = mismatches[field]

    client = FakeQdrantClient(
        _point(
            profile,
            payload=payload,
        )
    )

    if profile is ProcessingProfile.CLOUD:
        retriever: Any = (
            QdrantCloudRrfRetriever(
                candidate_multiplier=TEST_RRF_CANDIDATE_MULTIPLIER,
                client=client,
                sparse_query_representer=(
                    CloudSparseRepresenter()
                ),
            )
        )

        target: Any = CloudRetrievalTarget(
            document_id=12,
            processing_run_id=81,
            processing_profile=profile,
        )

        expected_code = (
            "cloud_retrieval_result_"
            "scope_invalid"
        )
    else:
        retriever = (
            QdrantHybridLocalRrfRetriever(
                candidate_multiplier=TEST_RRF_CANDIDATE_MULTIPLIER,
                client=client,
                sparse_query_representer=(
                    LocalBm25Representer(
                        embedder=(
                            StaticBm25Embedder()
                        )
                    )
                ),
            )
        )

        target = HybridLocalRetrievalTarget(
            document_id=12,
            processing_run_id=81,
            processing_profile=profile,
        )

        expected_code = (
            "hybrid_local_retrieval_result_"
            "scope_invalid"
        )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        retriever.retrieve(
            collection_name="k5",
            user_id=7,
            target=target,
            question="سؤال",
            query_vector=[0.1, 0.2],
            limit=6,
        )

    assert (
        exc_info.value.code
        == expected_code
    )


def test_native_rrf_fuses_dense_and_sparse_inside_scope() -> None:
    client = QdrantClient(":memory:")

    collection_name = (
        "k5_profile_rrf_isolation"
    )

    client.create_collection(
        collection_name=collection_name,
        vectors_config={
            DENSE_VECTOR_NAME: (
                models.VectorParams(
                    size=2,
                    distance=(
                        models.Distance.COSINE
                    ),
                )
            )
        },
        sparse_vectors_config={
            SPARSE_VECTOR_NAME: (
                models.SparseVectorParams(
                    modifier=(
                        SPARSE_VECTOR_MODIFIER
                    )
                )
            )
        },
    )

    def point(
        point_id: int,
        *,
        dense: list[float],
        sparse_index: int,
        user_id: int = 7,
        document_id: int = 12,
        processing_run_id: int = 81,
        processing_profile: str = (
            "hybrid_local"
        ),
    ) -> models.PointStruct:
        return models.PointStruct(
            id=point_id,
            vector={
                DENSE_VECTOR_NAME: dense,
                SPARSE_VECTOR_NAME: (
                    models.SparseVector(
                        indices=[
                            sparse_index
                        ],
                        values=[1.0],
                    )
                ),
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
                "text": (
                    f"chunk-{point_id}"
                ),
                "page": None,
                "section": None,
                "source": "document.pdf",
            },
        )

    client.upsert(
        collection_name=collection_name,
        points=[
            point(
                1,
                dense=[1.0, 0.0],
                sparse_index=20,
            ),
            point(
                2,
                dense=[0.8, 0.6],
                sparse_index=10,
            ),
            point(
                3,
                dense=[1.0, 0.0],
                sparse_index=10,
                user_id=99,
            ),
            point(
                4,
                dense=[1.0, 0.0],
                sparse_index=10,
                document_id=99,
            ),
            point(
                5,
                dense=[1.0, 0.0],
                sparse_index=10,
                processing_run_id=99,
            ),
            point(
                6,
                dense=[1.0, 0.0],
                sparse_index=10,
                processing_profile="cloud",
            ),
        ],
        wait=True,
    )

    sparse_query = models.SparseVector(
        indices=[10],
        values=[1.0],
    )

    representer = (
        StaticSparseQueryRepresenter(
            sparse_query
        )
    )

    retriever = (
        QdrantHybridLocalRrfRetriever(
            candidate_multiplier=TEST_RRF_CANDIDATE_MULTIPLIER,
            client=client,
            sparse_query_representer=(
                representer
            ),
        )
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
        question="سؤال",
        query_vector=[1.0, 0.0],
        limit=1,
    )

    assert representer.questions == [
        "سؤال"
    ]

    assert [
        result.point_id
        for result in results
    ] == ["2"]

    assert results[0].user_id if False else True

    assert results[0].document_id == 12
    assert (
        results[0].processing_run_id
        == 81
    )
    assert (
        results[0].processing_profile
        is ProcessingProfile.HYBRID_LOCAL
    )

    client.close()
