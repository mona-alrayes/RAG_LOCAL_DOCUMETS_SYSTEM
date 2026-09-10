from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.generation.base import LLMProvider
from app.generation.hugging_face import build_hugging_face_llm_provider
from app.generation.ollama import build_ollama_llm_provider
from app.generation.registry import LLMProviderRegistry
from app.processing.base import ProcessingProfile
from app.runtime.state import local_runtime_state
from app.services.capabilities import CapabilitiesService


def resolve_llm_provider(
    *,
    profile: ProcessingProfile,
    settings: Settings,
) -> LLMProvider:
    if not isinstance(profile, ProcessingProfile):
        raise ApplicationException(
            code="invalid_llm_provider_profile",
            message=(
                "LLM provider selection requires a trusted "
                "ProcessingProfile value."
            ),
        )

    provider = _build_provider(
        profile=profile,
        settings=settings,
    )

    capabilities = CapabilitiesService().build(
        settings,
        local_runtime_state.get(),
    )

    registry = LLMProviderRegistry([provider])

    return registry.resolve(
        profile=profile,
        capabilities=capabilities,
    )


def _build_provider(
    *,
    profile: ProcessingProfile,
    settings: Settings,
) -> LLMProvider:
    if profile is ProcessingProfile.CLOUD:
        return build_hugging_face_llm_provider(settings)

    if profile is ProcessingProfile.HYBRID_LOCAL:
        return build_ollama_llm_provider(settings)

    raise ApplicationException(
        code="invalid_llm_provider_profile",
        message=(
            "LLM provider selection requires a trusted "
            "ProcessingProfile value."
        ),
    )
