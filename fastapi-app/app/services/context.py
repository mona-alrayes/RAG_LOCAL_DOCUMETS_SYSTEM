from dataclasses import dataclass

from app.schemas.rag import RecentCompletedTurn
from app.services.cross_profile_rank_fusion import (
    RetrievalResult,
)


@dataclass(frozen=True, slots=True)
class ConversationContext:
    retrieved_chunks: tuple[RetrievalResult, ...]
    recent_completed_turns: tuple[
        RecentCompletedTurn,
        ...,
    ]


class ContextService:
    def build(
        self,
        *,
        retrieved_chunks: list[RetrievalResult],
        recent_completed_turns: list[
            RecentCompletedTurn
        ],
    ) -> ConversationContext:
        return ConversationContext(
            retrieved_chunks=tuple(
                retrieved_chunks
            ),
            recent_completed_turns=tuple(
                recent_completed_turns
            ),
        )
