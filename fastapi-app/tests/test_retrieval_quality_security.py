from types import SimpleNamespace
from typing import Any

import pytest
from qdrant_client import models

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.retrieval import (
    QdrantCloudRrfRetriever,
    QdrantHybridLocalRrfRetriever,
)
from app.processing.base import ProcessingProfile
from app.services.cloud_retrieval import (
    CloudRetrievalService,
    CloudRetrievalTarget,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalService,
    HybridLocalRetrievalTarget,
)


class NeverCalledDependency:
    def __init__(self) -> None:
        self.called = False

    def embed(self, question: str) -> list[float]:
        self.called = True
        raise AssertionError("Dependency must not be called.")

    def retrieve(self, **kwargs: Any) -> list[Any]:
        self.called = True
        raise AssertionError("Dependency must not be called.")

    def rerank(self, **kwargs: Any) -> list[Any]:
        self.called = True
        raise AssertionError("Dependency must not be called.")


@pytest.mark.parametrize(
    ("profile", "expected_code"),
    [
        (
            ProcessingProfile.CLOUD,
            "cloud_retrieval_limit_invalid",
        ),
        (
            ProcessingProfile.HYBRID_LOCAL,
            "hybrid_local_retrieval_limit_invalid",
        ),
    ],
)
@pytest.mark.parametrize(
    "limit",
    [0, -1, True, 1.5],
)
def test_profile_retrieval_rejects_invalid_limit_before_work(
    profile: ProcessingProfile,
    expected_code: str,
    limit: Any,
) -> None:
    dependency = NeverCalledDependency()

    if profile is ProcessingProfile.CLOUD:
        service: Any = CloudRetrievalService(
            settings=Settings(),
            query_embedder=dependency,
            dense_retriever=dependency,
            reranker=dependency,
        )

        target: Any = CloudRetrievalTarget(
            document_id=12,
            processing_run_id=81,
            processing_profile=profile,
        )
    else:
        service = HybridLocalRetrievalService(
            settings=Settings(),
            query_embedder=dependency,
            dense_retriever=dependency,
            reranker=dependency,
        )

        target = HybridLocalRetrievalTarget(
            document_id=12,
            processing_run_id=81,
            processing_profile=profile,
        )

    with pytest.raises(ApplicationException) as exc_info:
        service.retrieve(
            user_id=7,
            target=target,
            question="سؤال",
            limit=limit,
        )

    assert exc_info.value.code == expected_code
    assert dependency.called is False


class StaticQueryEmbedder:
    def embed(self, question: str) -> list[float]:
        return [0.1, 0.2]


class StaticSparseRepresenter:
    def represent_query(
        self,
        question: str,
    ) -> models.SparseVector:
        return models.SparseVector(
            indices=[10],
            values=[1.0],
        )


class PoisonedScopeClient:
    def __init__(
        self,
        profile: ProcessingProfile,
    ) -> None:
        self.profile = profile

    def query_points(self, **kwargs: Any) -> Any:
        return SimpleNamespace(
            points=[
                SimpleNamespace(
                    id="point-1",
                    score=0.9,
                    payload={
                        "user_id": 7,
                        "document_id": 12,
                        "processing_run_id": 999,
                        "processing_profile": (
                            self.profile.value
                        ),
                        "chunk_index": 1,
                        "text": "trusted text",
                        "page": 1,
                        "section": "section",
                        "source": "document.pdf",
                    },
                )
            ]
        )


class RecordingReranker:
    def __init__(self) -> None:
        self.called = False

    def rerank(self, **kwargs: Any) -> list[Any]:
        self.called = True
        raise AssertionError(
            "Reranker must not receive out-of-scope candidates."
        )


@pytest.mark.parametrize(
    (
        "profile",
        "expected_code",
    ),
    [
        (
            ProcessingProfile.CLOUD,
            "cloud_retrieval_result_scope_invalid",
        ),
        (
            ProcessingProfile.HYBRID_LOCAL,
            "hybrid_local_retrieval_result_scope_invalid",
        ),
    ],
)
def test_out_of_scope_rrf_result_fails_before_reranking(
    profile: ProcessingProfile,
    expected_code: str,
) -> None:
    client = PoisonedScopeClient(profile)

    if profile is ProcessingProfile.CLOUD:
        retriever: Any = QdrantCloudRrfRetriever(
            candidate_multiplier=2,
            client=client,
            sparse_query_representer=StaticSparseRepresenter(),
        )

        target: Any = CloudRetrievalTarget(
            document_id=12,
            processing_run_id=81,
            processing_profile=profile,
        )

        reranker = RecordingReranker()

        service: Any = CloudRetrievalService(
            settings=Settings(
                qdrant_cloud_collection="cloud-k10",
            ),
            query_embedder=StaticQueryEmbedder(),
            dense_retriever=retriever,
            reranker=reranker,
        )
    else:
        retriever = QdrantHybridLocalRrfRetriever(
            candidate_multiplier=2,
            client=client,
            sparse_query_representer=StaticSparseRepresenter(),
        )

        target = HybridLocalRetrievalTarget(
            document_id=12,
            processing_run_id=81,
            processing_profile=profile,
        )

        reranker = RecordingReranker()

        service = HybridLocalRetrievalService(
            settings=Settings(
                qdrant_hybrid_local_collection="local-k10",
            ),
            query_embedder=StaticQueryEmbedder(),
            dense_retriever=retriever,
            reranker=reranker,
        )

    with pytest.raises(ApplicationException) as exc_info:
        service.retrieve(
            user_id=7,
            target=target,
            question="سؤال",
            limit=2,
        )

    assert exc_info.value.code == expected_code
    assert reranker.called is False
