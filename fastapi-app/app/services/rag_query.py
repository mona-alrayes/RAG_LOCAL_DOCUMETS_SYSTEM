from collections.abc import (
    AsyncIterator,
    Callable,
)
from dataclasses import dataclass
from time import perf_counter

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.generation.base import (
    GenerationOptions,
    LLMProvider,
)
from app.generation.configuration import (
    generation_options_from_settings,
)
from app.generation.result import (
    AnswerResult,
    AnswerTimings,
)
from app.processing.base import ProcessingProfile
from app.schemas.rag import RagQueryRequest
from app.schemas.rag_events import (
    RagCompletedEvent,
    RagSource,
    RagTimings,
    RagTokenEvent,
)
from app.services.cloud_retrieval import (
    CloudRetrievalService,
    CloudRetrievalTarget,
)
from app.services.context import ContextService
from app.services.cross_profile_rank_fusion import (
    CrossProfileRankFusionService,
    RetrievalResult,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalService,
    HybridLocalRetrievalTarget,
)
from app.services.prompt import BuiltPrompt, PromptBuilder
from app.services.retrieval_observability import (
    RetrievalStageTimings,
)

LocalRetrievalFactory = Callable[
    [],
    HybridLocalRetrievalService,
]

ProviderResolver = Callable[
    [ProcessingProfile, Settings],
    LLMProvider,
]


@dataclass(frozen=True, slots=True)
class PreparedRagQuery:
    prompt: BuiltPrompt
    provider: LLMProvider
    options: GenerationOptions
    sources: tuple[RetrievalResult, ...]
    query_embedding_ms: float | None
    retrieval_ms: float | None
    reranking_ms: float | None
    fusion_ms: float
    context_building_ms: float
    started_at: float


class RagQueryService:
    def __init__(
        self,
        *,
        settings: Settings,
        cloud_retrieval: CloudRetrievalService,
        local_retrieval_factory: LocalRetrievalFactory,
        context_service: ContextService,
        prompt_builder: PromptBuilder,
        fusion_service: CrossProfileRankFusionService,
        provider_resolver: ProviderResolver,
        top_k: int = 5,
    ) -> None:
        self._settings = settings
        self._cloud_retrieval = cloud_retrieval
        self._local_retrieval_factory = local_retrieval_factory
        self._context_service = context_service
        self._prompt_builder = prompt_builder
        self._fusion_service = fusion_service
        self._provider_resolver = provider_resolver
        self._top_k = top_k

    def prepare(
        self,
        request: RagQueryRequest,
    ) -> PreparedRagQuery:
        started_at = perf_counter()

        (
            ranked_collections,
            retrieval_timings,
        ) = self._retrieve(request)

        fusion_started = perf_counter()

        retrieved_chunks = self._fusion_service.fuse(
            ranked_result_collections=ranked_collections,
            limit=self._top_k,
        )

        fusion_ms = self._milliseconds_since(
            fusion_started
        )

        context_started = perf_counter()

        context = self._context_service.build(
            retrieved_chunks=retrieved_chunks,
            recent_completed_turns=(
                request.recent_completed_turns
            ),
        )

        prompt = self._prompt_builder.build(
            question=request.question,
            context=context,
        )

        context_building_ms = self._milliseconds_since(
            context_started
        )

        provider = self._provider_resolver(
            self._settings.rag_generation_profile,
            self._settings,
        )

        return PreparedRagQuery(
            prompt=prompt,
            provider=provider,
            options=generation_options_from_settings(
                self._settings
            ),
            sources=tuple(retrieved_chunks),
            query_embedding_ms=(
                retrieval_timings.query_embedding
            ),
            retrieval_ms=(
                retrieval_timings.retrieval
            ),
            reranking_ms=(
                retrieval_timings.reranking
            ),
            fusion_ms=fusion_ms,
            context_building_ms=context_building_ms,
            started_at=started_at,
        )

    async def stream(
        self,
        prepared: PreparedRagQuery,
    ) -> AsyncIterator[
        RagTokenEvent | RagCompletedEvent
    ]:
        generation_started = perf_counter()
        answer_parts: list[str] = []

        async for token in prepared.provider.stream(
            prompt=prepared.prompt,
            options=prepared.options,
        ):
            answer_parts.append(token)

            yield RagTokenEvent(
                content=token,
            )

        generation_ms = self._milliseconds_since(
            generation_started
        )

        result = AnswerResult(
            answer="".join(answer_parts),
            sources=prepared.sources,
            timings_ms=AnswerTimings(
                query_embedding=(
                    prepared.query_embedding_ms
                ),
                retrieval=prepared.retrieval_ms,
                fusion=prepared.fusion_ms,
                reranking=prepared.reranking_ms,
                context_building=(
                    prepared.context_building_ms
                ),
                generation=generation_ms,
                total=self._milliseconds_since(
                    prepared.started_at
                ),
            ),
        )

        yield RagCompletedEvent(
            answer=result.answer,
            sources=[
                self._serialize_source(source)
                for source in result.sources
            ],
            timings_ms=RagTimings(
                query_embedding=(
                    result.timings_ms.query_embedding
                ),
                retrieval=result.timings_ms.retrieval,
                fusion=result.timings_ms.fusion,
                reranking=result.timings_ms.reranking,
                context_building=(
                    result.timings_ms.context_building
                ),
                generation=(
                    result.timings_ms.generation
                ),
                total=result.timings_ms.total,
            ),
        )

    def retrieve_for_evaluation(
        self, request: RagQueryRequest, *, k: int,
    ) -> list[RetrievalResult]:
        if not 1 <= k <= 20:
            raise ValueError('Evaluation k must be between 1 and 20.')
        ranked, _ = self._retrieve(request, top_k=k)
        return self._fusion_service.fuse(ranked_result_collections=ranked, limit=k)

    def _retrieve(
        self,
        request: RagQueryRequest,
        *,
        top_k: int | None = None,
    ) -> tuple[
        list[list[RetrievalResult]],
        RetrievalStageTimings,
    ]:
        ranked_collections: list[
            list[RetrievalResult]
        ] = []

        timings = RetrievalStageTimings(
            query_embedding=None,
            retrieval=None,
            reranking=None,
        )

        local_retrieval: (
            HybridLocalRetrievalService | None
        ) = None

        local_outcomes = None
        local_index = 0

        for target in request.document_targets:
            if (
                target.processing_profile
                is ProcessingProfile.CLOUD
            ):
                outcome = (
                    self._cloud_retrieval
                    .retrieve_observed(
                        user_id=request.user_id,
                        target=CloudRetrievalTarget(
                            document_id=target.document_id,
                            processing_run_id=(
                                target.processing_run_id
                            ),
                            processing_profile=(
                                target.processing_profile
                            ),
                        ),
                        question=request.question,
                        limit=top_k if top_k is not None else self._top_k,
                    )
                )

                ranked_collections.append(
                    list(outcome.results)
                )

                timings = self._merge_timings(
                    timings,
                    outcome.timings_ms,
                )
                continue

            if (
                target.processing_profile
                is ProcessingProfile.HYBRID_LOCAL
            ):
                if local_retrieval is None:
                    local_retrieval = (
                        self._local_retrieval_factory()
                    )

                if local_outcomes is None:
                    local_outcomes = local_retrieval.retrieve_many_observed(
                        user_id=request.user_id,
                        targets=[HybridLocalRetrievalTarget(
                            document_id=t.document_id,
                            processing_run_id=t.processing_run_id,
                            processing_profile=t.processing_profile,
                        ) for t in request.document_targets
                          if t.processing_profile is ProcessingProfile.HYBRID_LOCAL],
                        question=request.question,
                        limit=top_k if top_k is not None else self._top_k,
                    )
                outcome = local_outcomes[local_index]
                local_index += 1

                ranked_collections.append(
                    list(outcome.results)
                )

                timings = self._merge_timings(
                    timings,
                    outcome.timings_ms,
                )
                continue

            raise ApplicationException(
                code="rag_query_profile_invalid",
                message=(
                    "RAG query processing profile is invalid."
                ),
            )

        return ranked_collections, timings

    @staticmethod
    def _serialize_source(
        source: RetrievalResult,
    ) -> RagSource:
        return RagSource(
            point_id=source.point_id,
            retrieval_score=(
                source.retrieval_score
            ),
            reranker_score=(
                source.reranker_score
            ),
            document_id=source.document_id,
            processing_run_id=(
                source.processing_run_id
            ),
            processing_profile=(
                source.processing_profile
            ),
            chunk_index=source.chunk_index,
            text=source.text,
            page=source.page,
            section=source.section,
            source=source.source,
        )

    @classmethod
    def _merge_timings(
        cls,
        current: RetrievalStageTimings,
        incoming: RetrievalStageTimings,
    ) -> RetrievalStageTimings:
        return RetrievalStageTimings(
            query_embedding=cls._sum_optional(
                current.query_embedding,
                incoming.query_embedding,
            ),
            retrieval=cls._sum_optional(
                current.retrieval,
                incoming.retrieval,
            ),
            reranking=cls._sum_optional(
                current.reranking,
                incoming.reranking,
            ),
        )

    @staticmethod
    def _sum_optional(
        left: float | None,
        right: float | None,
    ) -> float | None:
        if left is None and right is None:
            return None

        return (left or 0.0) + (right or 0.0)

    @staticmethod
    def _milliseconds_since(
        started_at: float,
    ) -> float:
        return max(
            0.0,
            (perf_counter() - started_at) * 1000.0,
        )
