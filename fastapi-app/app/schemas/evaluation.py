from pydantic import BaseModel, ConfigDict, Field, PositiveInt, model_validator

from app.processing.base import ProcessingProfile
from app.schemas.rag import DocumentTarget


class RelevantChunk(BaseModel):
    model_config = ConfigDict(extra="forbid")
    document_id: PositiveInt
    chunk_index: int = Field(ge=0)


class EvaluationQuestionRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    user_id: PositiveInt
    question: str = Field(min_length=1, max_length=10000)
    document_targets: list[DocumentTarget] = Field(min_length=1, max_length=20)
    relevant_chunks: list[RelevantChunk] = Field(min_length=1, max_length=1000)
    k: int = Field(ge=1, le=20)

    @model_validator(mode="after")
    def validate_labels(self):
        ids = [target.document_id for target in self.document_targets]
        labels = [
            (label.document_id, label.chunk_index) for label in self.relevant_chunks
        ]
        if (
            len(ids) != len(set(ids))
            or len(labels) != len(set(labels))
            or any(doc not in ids for doc, _ in labels)
        ):
            raise ValueError(
                "Labels must be unique and belong to unique document targets."
            )
        if not self.question.strip():
            raise ValueError("Question cannot be blank.")
        return self


class RetrievedChunk(RelevantChunk):
    processing_run_id: PositiveInt
    processing_profile: ProcessingProfile
    point_id: str


class EvaluationQuestionResponse(BaseModel):
    metrics: dict[str, float]
    retrieved: list[RetrievedChunk]
    latency_ms: float
    config_snapshot: dict[str, str | int]
