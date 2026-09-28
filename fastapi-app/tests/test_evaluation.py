import asyncio
import json
from math import log2
from unittest.mock import MagicMock

import pytest
from pydantic import ValidationError

from app.core.config import Settings
from app.processing.base import ProcessingProfile
from app.schemas.evaluation import (
    BoundChunk,
    EvaluationQuestionRequest,
    GoldenBindingResult,
)
from app.services.cloud_retrieval import (
    CloudRetrievalOutcome,
    CloudRetrievalResult,
)
from app.services.context import ContextService
from app.services.cross_profile_rank_fusion import (
    CrossProfileRankFusionService,
)
from app.services.evaluation import (
    EvaluationService,
    retrieval_metrics,
)
from app.services.prompt import BuiltPrompt
from app.services.rag_query import RagQueryService
from app.services.retrieval_observability import (
    RetrievalStageTimings,
)


def test_metrics_match_hand_calculated_binary_ranking_and_empty_results():
    result = retrieval_metrics(
        [(12, 9), (12, 1), (12, 2)],
        {(12, 1), (12, 2), (12, 3)},
        3,
    )

    assert result["precision_at_k"] == pytest.approx(2 / 3)
    assert result["recall_at_k"] == pytest.approx(2 / 3)
    assert result["hit_rate_at_k"] == 1
    assert result["mrr_at_k"] == 0.5
    assert result["ndcg_at_k"] == pytest.approx(
        (1 / log2(3) + 1 / log2(4))
        / (1 + 1 / log2(3) + 1 / log2(4))
    )

    assert all(
        value == 0
        for value in retrieval_metrics(
            [],
            {(12, 1)},
            5,
        ).values()
    )

    assert (
        retrieval_metrics(
            [(12, 1), (12, 1)],
            {(12, 1)},
            5,
        )["precision_at_k"]
        == 0.2
    )


class FakeBinder:
    def bind(self, **_kwargs):
        return GoldenBindingResult(
            status="bound",
            relevant_chunks=[
                BoundChunk(
                    point_id="p1",
                    document_id=12,
                    processing_run_id=81,
                    processing_profile=ProcessingProfile.CLOUD,
                    chunk_index=1,
                    text="private chunk",
                    source="notes.txt",
                    page=1,
                    section="section",
                )
            ],
            evidence=[],
        )


class FakeProvider:
    profile = ProcessingProfile.CLOUD
    capability_name = "fake_llm"

    def __init__(self):
        self.calls = 0

    async def stream(self, *, prompt, options):
        self.calls += 1

        if self.calls == 1:
            yield "Reference answer"
            return

        # يحاكي عقد المقيّم الجديد مع اقتباس فعلي من المرجع والإجابة.
        if json.loads(prompt.user_content).get("metric") == "correctness":
            yield json.dumps({"checks": [{"reference_id": 1, "status": "supported", "answer_ids": [1]}], "short_reason": "Supported."})
            return

        yield (
            '{"score":1.0,'
            '"reason_code":"pass",'
            '"short_reason":"Supported."}'
        )


def test_full_evaluation_reuses_real_rag_path_and_generates_answer():
    cloud = MagicMock()

    cloud.retrieve_observed.return_value = CloudRetrievalOutcome(
        results=(
            CloudRetrievalResult(
                point_id="p1",
                retrieval_score=0.9,
                document_id=12,
                processing_run_id=81,
                processing_profile=ProcessingProfile.CLOUD,
                chunk_index=1,
                text="private chunk",
                page=1,
                section="section",
                source="notes.txt",
            ),
        ),
        timings_ms=RetrievalStageTimings(
            query_embedding=1,
            retrieval=2,
            reranking=3,
        ),
    )

    provider = FakeProvider()
    settings = Settings(
        _env_file=None,
        rag_rrf_candidate_multiplier=3,
        rag_rerank_candidate_multiplier=4,
    )

    context_service = MagicMock(spec=ContextService)
    context_service.build.return_value = "private chunk"

    prompt_builder = MagicMock()
    prompt_builder.build.return_value = BuiltPrompt(
        system_instructions="system",
        user_content="user",
    )

    rag = RagQueryService(
        settings=settings,
        cloud_retrieval=cloud,
        local_retrieval_factory=MagicMock(),
        context_service=context_service,
        prompt_builder=prompt_builder,
        fusion_service=CrossProfileRankFusionService(rrf_k=60),
        provider_resolver=lambda *_: provider,
    )

    request = EvaluationQuestionRequest(
        user_id=7,
        question_id="q1",
        question="Question?",
        reference_answer="Reference answer",
        split="held_out",
        category="factual",
        is_answerable=True,
        document_targets=[
            {
                "document_id": 12,
                "processing_run_id": 81,
                "processing_profile": "cloud",
            }
        ],
        evidence=[
            {
                "source": "notes.txt",
                "page": 1,
                "section": "section",
                "evidence_text": "private chunk",
            }
        ],
        k=3,
        pipeline="dense_sparse_rrf_reranker",
    )

    result = asyncio.run(
        EvaluationService(
            rag=rag,
            binder=FakeBinder(),
            settings=settings,
        ).evaluate(request)
    )

    assert result.status == "completed"
    assert result.generated_answer == "Reference answer"

    assert result.retrieval_metrics.precision_at_k == pytest.approx(
        1 / 3
    )
    assert result.retrieval_metrics.recall_at_k == 1
    assert result.retrieval_metrics.hit_rate_at_k == 1
    assert result.retrieval_metrics.mrr_at_k == 1
    assert result.retrieval_metrics.ndcg_at_k == 1

    assert result.generation_metrics.correctness.score == 1
    assert result.generation_metrics.faithfulness.score == 1
    assert result.generation_metrics.answer_relevance.score == 1
    assert (
        result.generation_metrics.abstention.status
        == "not_applicable"
    )

    assert result.retrieved[0].processing_run_id == 81
    assert result.retrieved[0].text == "private chunk"

    assert result.config_snapshot["pipeline"] == (
        "dense_sparse_rrf_reranker"
    )
    assert result.config_snapshot["metric_version"] == (
        "binary-chunk-v1"
    )
    assert result.config_snapshot["answer_prompt_version"] == (
        "rag-answer-v4"
    )

    assert (
        result.config_snapshot[
            "rrf_candidate_multiplier"
        ]
        == 3
    )
    assert (
        result.config_snapshot[
            "rerank_candidate_multiplier"
        ]
        == 4
    )
    assert (
        "fusion_rrf_k"
        not in result.config_snapshot
    )
    assert (
        "candidate_multiplier"
        not in result.config_snapshot
    )

    assert cloud.retrieve_observed.call_args.kwargs["limit"] == 3

    # One production answer + three independent judge calls.
    assert provider.calls == 4


def test_unanswerable_request_rejects_golden_evidence():
    with pytest.raises(ValidationError):
        EvaluationQuestionRequest(
            user_id=7,
            question_id="q1",
            question="Question?",
            reference_answer=None,
            split="held_out",
            category="unanswerable",
            is_answerable=False,
            document_targets=[
                {
                    "document_id": 12,
                    "processing_run_id": 81,
                    "processing_profile": "cloud",
                }
            ],
            evidence=[
                {
                    "source": "notes.txt",
                    "page": 1,
                    "section": None,
                    "evidence_text": "This must not exist.",
                }
            ],
            k=3,
        )


def test_answerable_request_requires_reference_and_evidence():
    with pytest.raises(ValidationError):
        EvaluationQuestionRequest(
            user_id=7,
            question_id="q1",
            question="Question?",
            reference_answer=None,
            split="held_out",
            category="factual",
            is_answerable=True,
            document_targets=[
                {
                    "document_id": 12,
                    "processing_run_id": 81,
                    "processing_profile": "cloud",
                }
            ],
            evidence=[],
            k=3,
        )
