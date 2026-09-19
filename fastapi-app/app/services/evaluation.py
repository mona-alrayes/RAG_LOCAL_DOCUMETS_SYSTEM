from hashlib import sha256
from math import log2
from time import perf_counter

from app.core.config import Settings
from app.schemas.evaluation import (
    EvaluationQuestionRequest,
    EvaluationQuestionResponse,
    EvaluationTimings,
    GenerationMetrics,
    JudgeMetric,
    RetrievalMetrics,
    RetrievedChunk,
)
from app.schemas.rag import RagQueryRequest
from app.schemas.rag_events import RagCompletedEvent
from app.services.generation_metrics import (
    GenerationMetricsEvaluator,
    judge_snapshot,
)
from app.services.golden_evidence_binding import GoldenEvidenceBinder
from app.services.prompt import SYSTEM_INSTRUCTIONS
from app.services.rag_query import RagQueryService
from app.services.retrieval_pipeline import (
    RetrievalPipeline,
)

RAG_PROMPT_VERSION = "rag-answer-v1"
RETRIEVAL_METRIC_VERSION = "binary-chunk-v1"


def retrieval_metrics(
    ranked: list[tuple[int, int]],
    relevant: set[tuple[int, int]],
    k: int,
) -> dict[str, float]:
    seen: set[tuple[int, int]] = set()
    gains: list[int] = []

    for identity in ranked[:k]:
        gains.append(
            int(
                identity in relevant
                and identity not in seen
            )
        )
        seen.add(identity)

    hits = sum(gains)

    dcg = sum(
        gain / log2(rank + 2)
        for rank, gain in enumerate(gains)
    )

    ideal = sum(
        1 / log2(rank + 2)
        for rank in range(min(k, len(relevant)))
    )

    return {
        "precision_at_k": hits / k,
        "recall_at_k": (
            hits / len(relevant)
            if relevant
            else 0.0
        ),
        "hit_rate_at_k": float(hits > 0),
        "mrr_at_k": next(
            (
                1 / (rank + 1)
                for rank, gain in enumerate(gains)
                if gain
            ),
            0.0,
        ),
        "ndcg_at_k": dcg / ideal if ideal else 0.0,
    }


class EvaluationService:
    def __init__(
        self,
        *,
        rag: RagQueryService,
        binder: GoldenEvidenceBinder,
        settings: Settings,
        generation_metrics: GenerationMetricsEvaluator | None = None,
    ) -> None:
        self.rag = rag
        self.binder = binder
        self.settings = settings
        self.generation_metrics = (
            generation_metrics
            or GenerationMetricsEvaluator()
        )

    async def evaluate(
        self,
        request: EvaluationQuestionRequest,
    ) -> EvaluationQuestionResponse:
        started = perf_counter()

        binding = self.binder.bind(
            user_id=request.user_id,
            targets=request.document_targets,
            evidence=request.evidence,
            is_answerable=request.is_answerable,
        )

        if binding.status in {
            "needs_review",
            "unbound",
        }:
            return EvaluationQuestionResponse(
                status="binding_failed",
                binding=binding,
                retrieval_metrics=RetrievalMetrics(),
                generated_answer=None,
                generation_metrics=self._not_run_generation_metrics(),
                retrieved=[],
                timings_ms=EvaluationTimings(
                    retrieval_ms=None,
                    generation_ms=None,
                    judge_ms=None,
                    total_ms=self._elapsed_ms(started),
                ),
                config_snapshot=self._config_snapshot(
                    request=request,
                    provider_name=None,
                    provider_model=None,
                ),
            )

        rag_request = RagQueryRequest(
            user_id=request.user_id,
            question=request.question,
            document_targets=request.document_targets,
            recent_completed_turns=[],
        )

        prepared = self.rag.prepare_for_evaluation(
            rag_request,
            k=request.k,
            pipeline=request.pipeline,
        )

        completed: RagCompletedEvent | None = None

        async for event in self.rag.stream(prepared):
            if isinstance(event, RagCompletedEvent):
                completed = event

        if completed is None:
            raise RuntimeError(
                "Evaluation generation did not complete."
            )

        retrieved = [
            RetrievedChunk(
                point_id=source.point_id,
                retrieval_score=source.retrieval_score,
                reranker_score=source.reranker_score,
                document_id=source.document_id,
                processing_run_id=source.processing_run_id,
                processing_profile=source.processing_profile,
                chunk_index=source.chunk_index,
                text=source.text,
                source=source.source,
                page=source.page,
                section=source.section,
            )
            for source in prepared.sources
        ]

        if request.is_answerable:
            labels = {
                (
                    chunk.document_id,
                    chunk.chunk_index,
                )
                for chunk in binding.relevant_chunks
            }

            calculated = retrieval_metrics(
                [
                    (
                        chunk.document_id,
                        chunk.chunk_index,
                    )
                    for chunk in retrieved
                ],
                labels,
                request.k,
            )

            retrieval_scores = RetrievalMetrics(
                **calculated
            )
        else:
            retrieval_scores = RetrievalMetrics()

        generation_scores, judge_ms = (
            await self.generation_metrics.evaluate(
                provider=prepared.provider,
                question=request.question,
                generated_answer=completed.answer,
                reference_answer=request.reference_answer,
                retrieved_context=[
                    source.text
                    for source in prepared.sources
                ],
                is_answerable=request.is_answerable,
            )
        )

        retrieval_ms = self._sum_optional(
            completed.timings_ms.query_embedding,
            completed.timings_ms.retrieval,
            completed.timings_ms.reranking,
            completed.timings_ms.fusion,
        )

        provider_model = (
            self.settings.cloud_llm_model
            if prepared.provider.profile.value == "cloud"
            else self.settings.local_llm_model
        )

        return EvaluationQuestionResponse(
            status="completed",
            binding=binding,
            retrieval_metrics=retrieval_scores,
            generated_answer=completed.answer,
            generation_metrics=generation_scores,
            retrieved=retrieved,
            timings_ms=EvaluationTimings(
                retrieval_ms=retrieval_ms,
                generation_ms=completed.timings_ms.generation,
                judge_ms=judge_ms,
                total_ms=self._elapsed_ms(started),
            ),
            config_snapshot=self._config_snapshot(
                request=request,
                provider_name=prepared.provider.capability_name,
                provider_model=provider_model,
            ),
        )

    def _config_snapshot(
        self,
        *,
        request: EvaluationQuestionRequest,
        provider_name: str | None,
        provider_model: str | None,
    ) -> dict[str, str | int | float | bool | None]:
        return {
            "pipeline": request.pipeline.value,
            "metric_version": RETRIEVAL_METRIC_VERSION,
            "k": request.k,
            "rrf_candidate_multiplier": (
                self.settings.rag_rrf_candidate_multiplier
                if request.pipeline
                is not RetrievalPipeline.DENSE_ONLY
                else None
            ),
            "rerank_candidate_multiplier": (
                self.settings.rag_rerank_candidate_multiplier
                if request.pipeline
                is RetrievalPipeline.FULL
                else None
            ),
            "cloud_embedding": self.settings.cloud_embed_model,
            "cloud_reranker": self.settings.cloud_rerank_model,
            "local_embedding": self.settings.local_embed_model,
            "local_reranker": self.settings.local_rerank_model,
            "answer_provider": provider_name,
            "answer_model": provider_model,
            "answer_temperature": self.settings.rag_generation_temperature,
            "answer_prompt_version": RAG_PROMPT_VERSION,
            "answer_prompt_sha256": sha256(
                SYSTEM_INSTRUCTIONS.encode("utf-8")
            ).hexdigest(),
            "judge_provider": provider_name,
            "judge_model": provider_model,
            "judge_temperature": 0.0,
            **judge_snapshot(),
        }

    @staticmethod
    def _not_run_generation_metrics() -> GenerationMetrics:
        metric = JudgeMetric(
            status="not_applicable",
            score=None,
        )

        return GenerationMetrics(
            correctness=metric,
            faithfulness=metric,
            answer_relevance=metric,
            abstention=metric,
        )

    @staticmethod
    def _sum_optional(
        *values: float | None,
    ) -> float | None:
        present = [
            value
            for value in values
            if value is not None
        ]

        return sum(present) if present else None

    @staticmethod
    def _elapsed_ms(started: float) -> float:
        return max(
            0.0,
            (perf_counter() - started) * 1000.0,
        )
