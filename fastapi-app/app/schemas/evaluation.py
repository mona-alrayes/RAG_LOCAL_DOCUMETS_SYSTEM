from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, PositiveInt, model_validator

from app.processing.base import ProcessingProfile
from app.schemas.rag import DocumentTarget
from app.services.retrieval_pipeline import RetrievalPipeline


class GoldenEvidence(BaseModel):
    model_config = ConfigDict(extra="forbid")

    source: str | None = Field(default=None, max_length=255)
    page: int | None = Field(default=None, ge=1)
    section: str | None = Field(default=None, max_length=255)
    evidence_text: str = Field(min_length=1, max_length=20000)

    @model_validator(mode="after")
    def validate_text(self):
        if not self.evidence_text.strip():
            raise ValueError("Evidence text cannot be blank.")
        return self


class EvaluationQuestionRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    user_id: PositiveInt
    question_id: str = Field(min_length=1, max_length=100)
    question: str = Field(min_length=1, max_length=10000)
    reference_answer: str | None = Field(default=None, max_length=20000)
    split: Literal["development", "held_out"]
    category: str | None = Field(default=None, max_length=100)
    is_answerable: bool

    document_targets: list[DocumentTarget] = Field(min_length=1, max_length=20)
    evidence: list[GoldenEvidence] = Field(default_factory=list, max_length=50)

    k: int = Field(ge=1, le=20)
    pipeline: RetrievalPipeline = RetrievalPipeline.FULL

    @model_validator(mode="after")
    def validate_request(self):
        ids = [
            (target.document_id, target.processing_run_id)
            for target in self.document_targets
        ]

        if len(ids) != len(set(ids)):
            raise ValueError("Document targets must be unique.")

        if not self.question.strip():
            raise ValueError("Question cannot be blank.")

        if not self.question_id.strip():
            raise ValueError("Question id cannot be blank.")

        if self.is_answerable:
            if not self.evidence:
                raise ValueError(
                    "Answerable questions require Golden Evidence."
                )

            if (
                self.reference_answer is None
                or not self.reference_answer.strip()
            ):
                raise ValueError(
                    "Answerable questions require a reference answer."
                )
        elif self.evidence:
            raise ValueError(
                "Unanswerable questions must not contain Golden Evidence."
            )

        return self


class BoundChunk(BaseModel):
    model_config = ConfigDict(extra="forbid")

    point_id: str
    document_id: PositiveInt
    processing_run_id: PositiveInt
    processing_profile: ProcessingProfile
    chunk_index: int = Field(ge=0)

    text: str
    source: str
    page: int | None
    section: str | None


class EvidenceBinding(BaseModel):
    model_config = ConfigDict(extra="forbid")

    evidence_index: int = Field(ge=0)
    status: Literal["bound", "needs_review", "unbound"]
    score: float | None = Field(default=None, ge=0, le=1)
    matched_chunks: list[BoundChunk] = Field(default_factory=list)
    competing_matches: int = Field(default=0, ge=0)


class GoldenBindingResult(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal[
        "bound",
        "not_applicable",
        "needs_review",
        "unbound",
    ]
    relevant_chunks: list[BoundChunk] = Field(default_factory=list)
    evidence: list[EvidenceBinding] = Field(default_factory=list)


class RetrievedChunk(BaseModel):
    model_config = ConfigDict(extra="forbid")

    point_id: str
    retrieval_score: float
    reranker_score: float | None

    document_id: PositiveInt
    processing_run_id: PositiveInt
    processing_profile: ProcessingProfile
    chunk_index: int = Field(ge=0)

    text: str
    source: str
    page: int | None
    section: str | None


class RetrievalMetrics(BaseModel):
    model_config = ConfigDict(extra="forbid")

    precision_at_k: float | None = Field(default=None, ge=0, le=1)
    recall_at_k: float | None = Field(default=None, ge=0, le=1)
    hit_rate_at_k: float | None = Field(default=None, ge=0, le=1)
    mrr_at_k: float | None = Field(default=None, ge=0, le=1)
    ndcg_at_k: float | None = Field(default=None, ge=0, le=1)


class JudgeMetric(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["completed", "failed", "not_applicable"]
    score: float | None = Field(default=None, ge=0, le=1)
    reason_code: str | None = Field(default=None, max_length=100)
    short_reason: str | None = Field(default=None, max_length=1000)


class GenerationMetrics(BaseModel):
    model_config = ConfigDict(extra="forbid")

    correctness: JudgeMetric
    faithfulness: JudgeMetric
    answer_relevance: JudgeMetric
    abstention: JudgeMetric


class EvaluationTimings(BaseModel):
    model_config = ConfigDict(extra="forbid")

    retrieval_ms: float | None = Field(default=None, ge=0)
    generation_ms: float | None = Field(default=None, ge=0)
    judge_ms: float | None = Field(default=None, ge=0)
    total_ms: float = Field(ge=0)


class EvaluationQuestionResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: Literal["completed", "binding_failed"]

    binding: GoldenBindingResult
    retrieval_metrics: RetrievalMetrics

    generated_answer: str | None
    generation_metrics: GenerationMetrics

    retrieved: list[RetrievedChunk]
    timings_ms: EvaluationTimings

    config_snapshot: dict[
        str,
        str | int | float | bool | None,
    ]
