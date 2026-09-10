from collections.abc import Iterator

from app.core.config import get_settings
from app.core.exceptions import ApplicationException
from app.generation.composition import resolve_llm_provider
from app.infrastructure.qdrant.client import (
    build_qdrant_client,
)
from app.infrastructure.qdrant.retrieval import (
    QdrantCloudRrfRetriever,
    QdrantHybridLocalRrfRetriever,
)
from app.processing.cloud_query_embeddings import (
    CloudJinaQueryEmbedder,
)
from app.processing.cloud_reranking import (
    CloudJinaReranker,
)
from app.processing.cloud_sparse import (
    CloudSparseRepresenter,
)
from app.processing.local_embeddings import (
    LocalBgeM3Embedder,
)
from app.processing.local_query_embeddings import (
    LocalBgeM3QueryEmbedder,
)
from app.processing.local_reranking import (
    LocalBgeReranker,
)
from app.processing.local_sparse import (
    LocalBm25Representer,
)
from app.runtime.state import (
    local_model_coordinator_state,
    local_runtime_state,
)
from app.services.cloud_retrieval import (
    CloudRetrievalService,
)
from app.services.context import ContextService
from app.services.cross_profile_rank_fusion import (
    CrossProfileRankFusionService,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalService,
)
from app.services.prompt import PromptBuilder
from app.services.rag_query import RagQueryService


def get_rag_query_service() -> Iterator[RagQueryService]:
    settings = get_settings()
    qdrant_client = build_qdrant_client(settings)

    cloud_retrieval = CloudRetrievalService(
        settings=settings,
        query_embedder=CloudJinaQueryEmbedder(
            api_key=(
                settings
                .jinaai_api_key
                .get_secret_value()
            ),
            model=settings.cloud_embed_model,
            rate_limit_retry_wait=(
                settings.rate_limit_retry_wait
            ),
            max_retries=settings.max_retries,
        ),
        dense_retriever=QdrantCloudRrfRetriever(
            client=qdrant_client,
            sparse_query_representer=(
                CloudSparseRepresenter()
            ),
        ),
        reranker=CloudJinaReranker(
            api_key=(
                settings
                .jinaai_api_key
                .get_secret_value()
            ),
            model=settings.cloud_rerank_model,
            rate_limit_retry_wait=(
                settings.rate_limit_retry_wait
            ),
            max_retries=settings.max_retries,
        ),
    )

    def build_local_retrieval(
    ) -> HybridLocalRetrievalService:
        runtime = local_runtime_state.get()
        coordinator = (
            local_model_coordinator_state.get()
        )

        if (
            runtime is None
            or coordinator is None
            or not runtime.ready
        ):
            raise ApplicationException(
                code=(
                    "hybrid_local_retrieval_unavailable"
                ),
                message=(
                    "Hybrid Local retrieval is unavailable."
                ),
            )

        text_embedder = LocalBgeM3Embedder(
            model=settings.local_embed_model,
            runtime=runtime,
            coordinator=coordinator,
            keep_alive_seconds=(
                settings
                .local_embed_keep_alive_seconds
            ),
        )

        return HybridLocalRetrievalService(
            settings=settings,
            query_embedder=(
                LocalBgeM3QueryEmbedder(
                    text_embedder=text_embedder,
                )
            ),
            dense_retriever=(
                QdrantHybridLocalRrfRetriever(
                    client=qdrant_client,
                    sparse_query_representer=(
                        LocalBm25Representer()
                    ),
                )
            ),
            reranker=LocalBgeReranker(
                model=settings.local_rerank_model,
                runtime=runtime,
                coordinator=coordinator,
                keep_alive_seconds=(
                    settings
                    .local_rerank_keep_alive_seconds
                ),
            ),
        )

    try:
        yield RagQueryService(
            settings=settings,
            cloud_retrieval=cloud_retrieval,
            local_retrieval_factory=(
                build_local_retrieval
            ),
            context_service=ContextService(),
            prompt_builder=PromptBuilder(),
            fusion_service=(
                CrossProfileRankFusionService()
            ),
            provider_resolver=(
                lambda profile, current_settings:
                resolve_llm_provider(
                    profile=profile,
                    settings=current_settings,
                )
            ),
        )
    finally:
        qdrant_client.close()
