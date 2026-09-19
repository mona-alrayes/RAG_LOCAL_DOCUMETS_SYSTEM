from dataclasses import dataclass
from time import perf_counter
from typing import Protocol

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.processing.base import ProcessingProfile
from app.processing.indexing import (
    resolve_qdrant_collection,
)
from app.services.retrieval_observability import (
    RetrievalStageTimings,
)
from app.services.retrieval_pipeline import RetrievalPipeline


@dataclass(frozen=True, slots=True)
class CloudRetrievalTarget:
    document_id: int
    processing_run_id: int
    processing_profile: ProcessingProfile


@dataclass(frozen=True, slots=True)
class CloudRetrievalResult:
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
class CloudRetrievalOutcome:
    results: tuple[CloudRetrievalResult, ...]
    timings_ms: RetrievalStageTimings


class CloudQueryEmbedder(Protocol):
    def embed(
        self,
        question: str,
    ) -> list[float]:
        ...


class CloudRetriever(Protocol):
    def retrieve(
        self,
        *,
        collection_name: str,
        user_id: int,
        target: CloudRetrievalTarget,
        question: str,
        query_vector: list[float],
        limit: int,
    ) -> list[CloudRetrievalResult]:
        ...


class CloudReranker(Protocol):
    def rerank(
        self,
        *,
        question: str,
        candidates: list[
            CloudRetrievalResult
        ],
        limit: int,
    ) -> list[CloudRetrievalResult]:
        ...


class CloudRetrievalService:
    def __init__(
        self,
        *,
        settings: Settings,
        query_embedder: CloudQueryEmbedder,
        dense_retriever: CloudRetriever,
        reranker: CloudReranker,
    ) -> None:
        self._settings = settings
        self._query_embedder = query_embedder
        self._retriever = dense_retriever
        self._reranker = reranker

    def retrieve(
        self,
        *,
        user_id: int,
        target: CloudRetrievalTarget,
        question: str,
        limit: int,
    ) -> list[CloudRetrievalResult]:
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
        target: CloudRetrievalTarget,
        question: str,
        limit: int,
        pipeline: RetrievalPipeline = RetrievalPipeline.FULL,
    ) -> CloudRetrievalOutcome:
        self._validate(
            target=target,
            limit=limit,
        )

        collection_name = (
            resolve_qdrant_collection(
                profile=target.processing_profile,
                settings=self._settings,
            )
        )

        embedding_started = perf_counter()

        query_vector = self._query_embedder.embed(
            question
        )

        query_embedding_ms = self._elapsed_ms(
            embedding_started
        )

        candidate_limit = (
            limit
            * (
                self._settings.rag_rerank_candidate_multiplier
                if pipeline is RetrievalPipeline.FULL
                else 1
            )
        )

        retrieval_started = perf_counter()

        candidates = self._retriever.retrieve(
            collection_name=collection_name,
            user_id=user_id,
            target=target,
            question=question,
            query_vector=query_vector,
            limit=candidate_limit,
            **({"dense_only": True} if pipeline is RetrievalPipeline.DENSE_ONLY else {}),
        )

        retrieval_ms = self._elapsed_ms(
            retrieval_started
        )

        if not candidates or pipeline is not RetrievalPipeline.FULL:
            return CloudRetrievalOutcome(
                results=tuple(candidates[:limit]),
                timings_ms=RetrievalStageTimings(
                    query_embedding=query_embedding_ms,
                    retrieval=retrieval_ms,
                    reranking=None,
                ),
            )

        reranking_started = perf_counter()

        results = self._reranker.rerank(
            question=question,
            candidates=candidates,
            limit=limit,
        )

        reranking_ms = self._elapsed_ms(
            reranking_started
        )

        return CloudRetrievalOutcome(
            results=tuple(results),
            timings_ms=RetrievalStageTimings(
                query_embedding=query_embedding_ms,
                retrieval=retrieval_ms,
                reranking=reranking_ms,
            ),
        )

    @staticmethod
    def _elapsed_ms(started_at: float) -> float:
        return max(
            0.0,
            (perf_counter() - started_at) * 1000.0,
        )

    @staticmethod
    def _validate(
        *,
        target: CloudRetrievalTarget,
        limit: int,
    ) -> None:
        if (
            target.processing_profile
            is not ProcessingProfile.CLOUD
        ):
            raise ApplicationException(
                code="cloud_retrieval_target_invalid",
                message=(
                    "Cloud retrieval requires "
                    "a cloud processing target."
                ),
            )

        if (
            isinstance(limit, bool)
            or not isinstance(limit, int)
            or limit < 1
        ):
            raise ApplicationException(
                code="cloud_retrieval_limit_invalid",
                message=(
                    "Cloud retrieval limit "
                    "must be a positive integer."
                ),
            )
