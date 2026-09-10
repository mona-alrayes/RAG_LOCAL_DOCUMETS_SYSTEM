from math import log2
from time import perf_counter

from app.core.config import Settings
from app.schemas.evaluation import (
    EvaluationQuestionRequest,
    EvaluationQuestionResponse,
    RetrievedChunk,
)
from app.schemas.rag import RagQueryRequest
from app.services.rag_query import RagQueryService


def retrieval_metrics(
    ranked: list[tuple[int, int]], relevant: set[tuple[int, int]], k: int
) -> dict[str, float]:
    # Binary chunk relevance, duplicate retrievals count only once, missing ranks are misses.
    seen = set()
    gains = []
    for identity in ranked[:k]:
        gains.append(int(identity in relevant and identity not in seen))
        seen.add(identity)
    hits = sum(gains)
    dcg = sum(gain / log2(rank + 2) for rank, gain in enumerate(gains))
    ideal = sum(1 / log2(rank + 2) for rank in range(min(k, len(relevant))))
    return {
        "precision_at_k": hits / k,
        "recall_at_k": hits / len(relevant) if relevant else 0.0,
        "hit_rate_at_k": float(hits > 0),
        "mrr_at_k": next(
            (1 / (rank + 1) for rank, gain in enumerate(gains) if gain), 0.0
        ),
        "ndcg_at_k": dcg / ideal if ideal else 0.0,
    }


class EvaluationService:
    def __init__(self, *, rag: RagQueryService, settings: Settings):
        self.rag = rag
        self.settings = settings

    def evaluate(
        self, request: EvaluationQuestionRequest
    ) -> EvaluationQuestionResponse:
        started = perf_counter()
        results = self.rag.retrieve_for_evaluation(
            RagQueryRequest(
                user_id=request.user_id,
                question=request.question,
                document_targets=request.document_targets,
            ),
            k=request.k,
        )
        return EvaluationQuestionResponse(
            metrics=retrieval_metrics(
                [(r.document_id, r.chunk_index) for r in results],
                {(r.document_id, r.chunk_index) for r in request.relevant_chunks},
                request.k,
            ),
            retrieved=[
                RetrievedChunk(
                    document_id=r.document_id,
                    processing_run_id=r.processing_run_id,
                    processing_profile=r.processing_profile,
                    chunk_index=r.chunk_index,
                    point_id=r.point_id,
                )
                for r in results
            ],
            latency_ms=max(0.0, (perf_counter() - started) * 1000),
            config_snapshot={
                "pipeline": "dense_sparse_rrf_reranker",
                "metric_version": "binary-chunk-v1",
                "k": request.k,
                "fusion_rrf_k": 60,
                "candidate_multiplier": 2,
                "cloud_embedding": self.settings.cloud_embed_model,
                "cloud_reranker": self.settings.cloud_rerank_model,
                "local_embedding": self.settings.local_embed_model,
                "local_reranker": self.settings.local_rerank_model,
            },
        )
