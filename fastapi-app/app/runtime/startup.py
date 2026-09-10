from app.core.config import DeploymentMode, Settings
from app.runtime.state import (
    local_model_coordinator_state,
    local_runtime_state,
)


def initialize_local_runtime(settings: Settings) -> None:
    local_runtime_state.clear()
    local_model_coordinator_state.clear()

    if settings.rag_deployment_mode is not DeploymentMode.LOCAL:
        return

    from app.runtime.resolver import LocalRuntimeResolver
    from app.runtime.telemetry import PsutilResourceTelemetry
    from app.runtime.torch_runtime import TorchRuntimeAdapter

    runtime = TorchRuntimeAdapter()
    telemetry = PsutilResourceTelemetry()

    resolver = LocalRuntimeResolver(
        runtime=runtime,
        telemetry=telemetry,
    )

    snapshot = resolver.resolve(settings.local_device)

    local_runtime_state.set(snapshot)

    if (
        not snapshot.ready
        or snapshot.selected_backend is None
        or snapshot.selected_dtype is None
    ):
        return

    from app.runtime.memory_policy import LocalMemoryPolicy, OllamaCache
    from app.runtime.model_coordinator import LocalModelCoordinator
    memory_policy = LocalMemoryPolicy(
        reserve_bytes=int(settings.local_system_reserve_gib * 1024**3),
        stage_bytes={
            settings.local_embed_model: int(settings.local_embedding_headroom_gib * 1024**3),
            settings.local_rerank_model: int(settings.local_reranker_headroom_gib * 1024**3),
            'ollama': int(settings.local_llm_headroom_gib * 1024**3),
        },
        cache=OllamaCache(base_url=settings.ollama_base_url, model=settings.local_llm_model),
    )
    coordinator = LocalModelCoordinator(
        memory_policy=memory_policy,
        runtime_snapshot=snapshot,
        telemetry=telemetry,
        runtime=runtime,
        min_available_memory_ratio=(
            settings.local_min_available_memory_ratio
        ),
        max_concurrency=settings.local_ai_max_concurrency,
    )

    local_model_coordinator_state.set(coordinator)
