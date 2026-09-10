from dataclasses import dataclass
from time import perf_counter
from typing import Protocol

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.processing.base import ProcessingProfile
from app.processing.indexing import resolve_qdrant_collection
from app.services.retrieval_observability import (
    RetrievalStageTimings,
)

HYBRID_LOCAL_RERANK_CANDIDATE_MULTIPLIER = 2


@dataclass(frozen=True, slots=True)
class HybridLocalRetrievalTarget:
    document_id: int
    processing_run_id: int
    processing_profile: ProcessingProfile


@dataclass(frozen=True, slots=True)
class HybridLocalRetrievalResult:
    point_id: str
    retrieval_score: float
    document_id: int
    processing_run_id: int
    processing_profile: ProcessingProfile
    chunk_index: int
    text: str
    page: int | None
    section: str | None
    source: str
    reranker_score: float | None = None


@dataclass(frozen=True, slots=True)
class HybridLocalRetrievalOutcome:
    results: tuple[
        HybridLocalRetrievalResult,
        ...
    ]
    timings_ms: RetrievalStageTimings


class HybridLocalQueryEmbedder(Protocol):
    def embed(
        self,
        question: str,
    ) -> list[float]:
        ...


class HybridLocalRetriever(Protocol):
    def retrieve(
        self,
        *,
        collection_name: str,
        user_id: int,
        target: HybridLocalRetrievalTarget,
        question: str,
        query_vector: list[float],
        limit: int,
    ) -> list[HybridLocalRetrievalResult]:
        ...


class HybridLocalReranker(Protocol):
    def rerank(
        self,
        *,
        question: str,
        candidates: list[
            HybridLocalRetrievalResult
        ],
        limit: int,
    ) -> list[HybridLocalRetrievalResult]:
        ...


class HybridLocalRetrievalService:
    def __init__(
        self,
        *,
        settings: Settings,
        query_embedder: HybridLocalQueryEmbedder,
        dense_retriever: HybridLocalRetriever,
        reranker: HybridLocalReranker,
    ) -> None:
        self._settings = settings
        self._query_embedder = query_embedder
        self._retriever = dense_retriever
        self._reranker = reranker

    def retrieve(
        self,
        *,
        user_id: int,
        target: HybridLocalRetrievalTarget,
        question: str,
        limit: int,
    ) -> list[HybridLocalRetrievalResult]:
        return list(
            self.retrieve_observed(
                user_id=user_id,
                target=target,
                question=question,
                limit=limit,
            ).results
        )

    def retrieve_observed(
        self,
        *,
        user_id: int,
        target: HybridLocalRetrievalTarget,
        question: str,
        limit: int,
    ) -> HybridLocalRetrievalOutcome:
        return self.retrieve_many_observed(
            user_id=user_id, targets=[target], question=question, limit=limit,
        )[0]

    def retrieve_many_observed(
        self, *, user_id: int, targets: list[HybridLocalRetrievalTarget],
        question: str, limit: int,
    ) -> list[HybridLocalRetrievalOutcome]:
        for target in targets:
            self._validate(target=target, limit=limit)
        if not targets:
            return []
        started = perf_counter()
        query_vector = self._query_embedder.embed(question)
        embedding_ms = self._elapsed_ms(started)
        groups = []
        retrieval_times = []
        for target in targets:
            started = perf_counter()
            groups.append(self._retriever.retrieve(
                collection_name=resolve_qdrant_collection(
                    profile=target.processing_profile, settings=self._settings),
                user_id=user_id, target=target, question=question,
                query_vector=query_vector,
                limit=limit * HYBRID_LOCAL_RERANK_CANDIDATE_MULTIPLIER,
            ))
            retrieval_times.append(self._elapsed_ms(started))
        candidates = [candidate for group in groups for candidate in group]
        started = perf_counter()
        ranked = self._reranker.rerank(
            question=question, candidates=candidates, limit=len(candidates),
        ) if candidates else []
        reranking_ms = self._elapsed_ms(started) if candidates else None
        # Score once, then restore document groups before existing rank fusion.
        # Stable score sorting preserves the original tie order within each group.
        return [HybridLocalRetrievalOutcome(
            results=tuple(r for r in ranked if r.document_id == t.document_id
                          and r.processing_run_id == t.processing_run_id)[:limit],
            timings_ms=RetrievalStageTimings(
                query_embedding=embedding_ms if i == 0 else None,
                retrieval=retrieval_times[i],
                reranking=reranking_ms if i == 0 else None,
            ),
        ) for i, t in enumerate(targets)]

    @staticmethod
    def _elapsed_ms(started_at: float) -> float:
        return max(
            0.0,
            (perf_counter() - started_at) * 1000.0,
        )

    @staticmethod
    def _validate(
        *,
        target: HybridLocalRetrievalTarget,
        limit: int,
    ) -> None:
        if (
            target.processing_profile
            is not ProcessingProfile.HYBRID_LOCAL
        ):
            raise ApplicationException(
                code="hybrid_local_retrieval_target_invalid",
                message=(
                    "Hybrid Local retrieval requires "
                    "a hybrid_local processing target."
                ),
            )

        if (
            isinstance(limit, bool)
            or not isinstance(limit, int)
            or limit < 1
        ):
            raise ApplicationException(
                code="hybrid_local_retrieval_limit_invalid",
                message=(
                    "Hybrid Local retrieval limit "
                    "must be a positive integer."
                ),
            )
