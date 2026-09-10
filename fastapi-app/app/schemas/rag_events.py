from typing import Literal

from pydantic import BaseModel, ConfigDict

from app.processing.base import ProcessingProfile


class RagEventModel(BaseModel):
    model_config = ConfigDict(
        extra="forbid",
        allow_inf_nan=False,
    )


class RagSource(RagEventModel):
    point_id: str
    retrieval_score: float
    reranker_score: float | None
    document_id: int
    processing_run_id: int
    processing_profile: ProcessingProfile
    chunk_index: int
    text: str
    page: int | None
    section: str | None
    source: str


class RagTimings(RagEventModel):
    query_embedding: float | None
    retrieval: float | None
    fusion: float | None
    reranking: float | None
    context_building: float | None
    generation: float | None
    total: float


class RagTokenEvent(RagEventModel):
    type: Literal["token"] = "token"
    content: str


class RagCompletedEvent(RagEventModel):
    type: Literal["completed"] = "completed"
    answer: str
    sources: list[RagSource]
    timings_ms: RagTimings


class RagErrorEvent(RagEventModel):
    type: Literal["error"] = "error"
    code: str
    message: str


type RagStreamEvent = (
    RagTokenEvent
    | RagCompletedEvent
    | RagErrorEvent
)
