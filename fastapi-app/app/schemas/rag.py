from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    PositiveInt,
)

from app.processing.base import ProcessingProfile


class DocumentTarget(BaseModel):
    model_config = ConfigDict(extra="forbid")

    document_id: PositiveInt
    processing_run_id: PositiveInt
    processing_profile: ProcessingProfile


class RecentCompletedTurn(BaseModel):
    model_config = ConfigDict(extra="forbid")

    user: str
    assistant: str


class RagQueryRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    user_id: PositiveInt
    question: str = Field(min_length=1)
    document_targets: list[DocumentTarget] = Field(
        default_factory=list,
    )
    recent_completed_turns: list[RecentCompletedTurn] = Field(
        default_factory=list,
        max_length=2,
    )
