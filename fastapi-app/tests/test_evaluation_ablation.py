from types import SimpleNamespace

import pytest
from pydantic import ValidationError
from qdrant_client import models

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.retrieval import (
    QdrantCloudRrfRetriever,
    QdrantHybridLocalRrfRetriever,
)
from app.schemas.rag import RagQueryRequest
from app.services.cloud_retrieval import CloudRetrievalService
from app.services.cross_profile_rank_fusion import (
    CrossProfileRankFusionService,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalService,
)
from app.services.rag_query import RagQueryService
from app.services.retrieval_pipeline import RetrievalPipeline


@pytest.mark.parametrize(
    "profile",
    ["cloud", "hybrid_local"],
)
@pytest.mark.parametrize(
    ("pipeline", "sparse_calls", "rerank_calls"),
    [
        ("dense_only", 0, 0),
        ("dense_sparse_rrf", 1, 0),
        ("dense_sparse_rrf_reranker", 1, 1),
    ],
)
@pytest.mark.parametrize(
    "wrong_scope",
    [False, True],
)
def test_evaluation_retrieval_ablation_stages_and_scope(
    profile,
    pipeline,
    sparse_calls,
    rerank_calls,
    wrong_scope,
):
    calls = {
        "sparse": 0,
        "rerank": 0,
        "query": [],
    }

    class Client:
        def query_points(self, **kwargs):
            calls["query"].append(kwargs)

            return SimpleNamespace(
                points=[
                    SimpleNamespace(
                        id=1,
                        score=0.8,
                        payload={
                            "user_id": (
                                99 if wrong_scope else 7
                            ),
                            "document_id": 12,
                            "processing_run_id": 81,
                            "processing_profile": profile,
                            "chunk_index": 1,
                            "text": "Evidence",
                            "page": None,
                            "section": None,
                            "source": "notes.txt",
                        },
                    )
                ]
            )

    class Sparse:
        def represent_query(self, question):
            calls["sparse"] += 1

            return models.SparseVector(
                indices=[1],
                values=[1.0],
            )

    class Embedder:
        def embed(self, question):
            return [1.0, 0.0]

    class Reranker:
        def rerank(
            self,
            *,
            candidates,
            limit,
            **kwargs,
        ):
            calls["rerank"] += 1
            return candidates[:limit]

    settings = Settings(_env_file=None)

    retriever_cls, service_cls = (
        (
            QdrantCloudRrfRetriever,
            CloudRetrievalService,
        )
        if profile == "cloud"
        else (
            QdrantHybridLocalRrfRetriever,
            HybridLocalRetrievalService,
        )
    )

    retrieval = service_cls(
        settings=settings,
        query_embedder=Embedder(),
        dense_retriever=retriever_cls(
            client=Client(),
            sparse_query_representer=Sparse(),
            candidate_multiplier=(
                settings.rag_rrf_candidate_multiplier
            ),
        ),
        reranker=Reranker(),
    )

    rag = RagQueryService(
        settings=settings,
        cloud_retrieval=retrieval,
        local_retrieval_factory=lambda: retrieval,
        context_service=None,
        prompt_builder=None,
        fusion_service=CrossProfileRankFusionService(rrf_k=60),
        provider_resolver=lambda *_: pytest.fail(
            "Retrieval-only ablation must not resolve an LLM."
        ),
    )

    request = RagQueryRequest(
        user_id=7,
        question="Evidence?",
        document_targets=[
            {
                "document_id": 12,
                "processing_run_id": 81,
                "processing_profile": profile,
            }
        ],
    )

    selected_pipeline = RetrievalPipeline(pipeline)

    if wrong_scope:
        with pytest.raises(
            ApplicationException,
            match="trusted",
        ):
            rag.retrieve_for_evaluation(
                request,
                k=3,
                pipeline=selected_pipeline,
            )
    else:
        result = rag.retrieve_for_evaluation(
            request,
            k=3,
            pipeline=selected_pipeline,
        )

        assert len(result) == 1
        assert result[0].document_id == 12
        assert result[0].processing_run_id == 81
        assert result[0].chunk_index == 1
        assert calls["rerank"] == rerank_calls

    assert calls["sparse"] == sparse_calls

    query = calls["query"][0]

    assert {
        condition.key: condition.match.value
        for condition in query["query_filter"].must
    } == {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
        "processing_profile": profile,
    }

    assert ("prefetch" in query) == (
        pipeline != "dense_only"
    )

    if pipeline != "dense_only":
        rrf_output_limit = (
            3
            * (
                settings.rag_rerank_candidate_multiplier
                if pipeline
                == "dense_sparse_rrf_reranker"
                else 1
            )
        )

        assert query["limit"] == rrf_output_limit

        assert all(
            prefetch.limit
            == (
                rrf_output_limit
                * settings.rag_rrf_candidate_multiplier
            )
            for prefetch in query["prefetch"]
        )


def test_chat_cannot_choose_evaluation_pipeline():
    with pytest.raises(ValidationError):
        RagQueryRequest(
            user_id=7,
            question="test",
            pipeline="dense_only",
        )
