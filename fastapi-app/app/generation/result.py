from dataclasses import dataclass

from app.services.cross_profile_rank_fusion import RetrievalResult

type AnswerSource = RetrievalResult


@dataclass(frozen=True, slots=True)
class AnswerTimings:
    query_embedding: float | None
    retrieval: float | None
    fusion: float | None
    reranking: float | None
    context_building: float | None
    generation: float | None
    total: float


@dataclass(frozen=True, slots=True)
class AnswerResult:
    answer: str
    sources: tuple[AnswerSource, ...]
    timings_ms: AnswerTimings
