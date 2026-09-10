from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class RetrievalStageTimings:
    query_embedding: float | None
    retrieval: float | None
    reranking: float | None
