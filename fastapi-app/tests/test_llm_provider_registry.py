import asyncio
from collections.abc import AsyncIterator

import pytest

from app.core.config import DeploymentMode
from app.core.exceptions import ApplicationException
from app.generation.base import GenerationOptions, LLMProvider
from app.generation.registry import LLMProviderRegistry
from app.processing.base import ProcessingProfile
from app.schemas.capabilities import (
    DeploymentCapabilitiesResponse,
    ProviderCapability,
    ProviderStatus,
)
from app.services.prompt import BuiltPrompt


class FakeLLMProvider(LLMProvider):
    def __init__(
        self,
        *,
        profile: ProcessingProfile,
        capability_name: str,
    ) -> None:
        self._profile = profile
        self._capability_name = capability_name
        self.stream_calls = 0
        self.received_prompt: BuiltPrompt | None = None
        self.received_options: GenerationOptions | None = None

    @property
    def profile(self) -> ProcessingProfile:
        return self._profile

    @property
    def capability_name(self) -> str:
        return self._capability_name

    def stream(
        self,
        *,
        prompt: BuiltPrompt,
        options: GenerationOptions,
    ) -> AsyncIterator[str]:
        self.stream_calls += 1
        self.received_prompt = prompt
        self.received_options = options

        async def generate() -> AsyncIterator[str]:
            yield "token-1"
            yield "token-2"

        return generate()


def cloud_provider() -> FakeLLMProvider:
    return FakeLLMProvider(
        profile=ProcessingProfile.CLOUD,
        capability_name="hugging_face_llm",
    )


def local_provider() -> FakeLLMProvider:
    return FakeLLMProvider(
        profile=ProcessingProfile.HYBRID_LOCAL,
        capability_name="ollama_llm",
    )


def capabilities(
    *,
    cloud_status: ProviderStatus = ProviderStatus.AVAILABLE,
    local_status: ProviderStatus = ProviderStatus.AVAILABLE,
) -> DeploymentCapabilitiesResponse:
    return DeploymentCapabilitiesResponse(
        deployment_mode=DeploymentMode.LOCAL,
        supported_profiles=["cloud", "hybrid_local"],
        available_profiles=["cloud", "hybrid_local"],
        providers=[
            ProviderCapability(
                provider="hugging_face_llm",
                status=cloud_status,
            ),
            ProviderCapability(
                provider="ollama_llm",
                status=local_status,
            ),
        ],
    )


async def collect_tokens(stream: AsyncIterator[str]) -> list[str]:
    return [token async for token in stream]


def test_llm_provider_contract_streams_built_prompt_with_typed_options() -> None:
    provider = cloud_provider()
    prompt = BuiltPrompt(
        system_instructions="System instructions",
        user_content="User content",
    )
    options = GenerationOptions(temperature=0.2)

    tokens = asyncio.run(
        collect_tokens(
            provider.stream(
                prompt=prompt,
                options=options,
            )
        )
    )

    assert tokens == ["token-1", "token-2"]
    assert provider.received_prompt is prompt
    assert provider.received_options is options


def test_registry_resolves_cloud_provider() -> None:
    cloud = cloud_provider()
    registry = LLMProviderRegistry([cloud])

    resolved = registry.resolve(
        profile=ProcessingProfile.CLOUD,
        capabilities=capabilities(),
    )

    assert resolved is cloud


def test_registry_resolves_hybrid_local_provider() -> None:
    local = local_provider()
    registry = LLMProviderRegistry([local])

    resolved = registry.resolve(
        profile=ProcessingProfile.HYBRID_LOCAL,
        capabilities=capabilities(),
    )

    assert resolved is local


def test_registry_returns_same_registered_provider_object() -> None:
    cloud = cloud_provider()
    registry = LLMProviderRegistry([cloud])

    first = registry.resolve(
        profile=ProcessingProfile.CLOUD,
        capabilities=capabilities(),
    )
    second = registry.resolve(
        profile=ProcessingProfile.CLOUD,
        capabilities=capabilities(),
    )

    assert first is cloud
    assert second is cloud
    assert first is second


def test_registry_rejects_unregistered_profile() -> None:
    registry = LLMProviderRegistry([])

    with pytest.raises(ApplicationException) as exc_info:
        registry.resolve(
            profile=ProcessingProfile.CLOUD,
            capabilities=capabilities(),
        )

    assert exc_info.value.code == "llm_provider_not_registered"


def test_registry_rejects_unavailable_provider() -> None:
    cloud = cloud_provider()
    registry = LLMProviderRegistry([cloud])

    with pytest.raises(ApplicationException) as exc_info:
        registry.resolve(
            profile=ProcessingProfile.CLOUD,
            capabilities=capabilities(
                cloud_status=ProviderStatus.UNAVAILABLE,
            ),
        )

    assert exc_info.value.code == "llm_provider_unavailable"


def test_registry_rejects_duplicate_profile_registration() -> None:
    first = cloud_provider()
    second = cloud_provider()

    with pytest.raises(ApplicationException) as exc_info:
        LLMProviderRegistry([first, second])

    assert (
        exc_info.value.code
        == "llm_provider_profile_already_registered"
    )


def test_registry_does_not_fallback_to_cloud_for_unavailable_local() -> None:
    cloud = cloud_provider()
    local = local_provider()
    registry = LLMProviderRegistry([cloud, local])

    with pytest.raises(ApplicationException) as exc_info:
        registry.resolve(
            profile=ProcessingProfile.HYBRID_LOCAL,
            capabilities=capabilities(
                local_status=ProviderStatus.UNAVAILABLE,
            ),
        )

    assert exc_info.value.code == "llm_provider_unavailable"


@pytest.mark.parametrize(
    "untrusted_selection",
    ["cloud", "hugging_face_llm"],
)
def test_registry_rejects_free_string_provider_selection(
    untrusted_selection: str,
) -> None:
    registry = LLMProviderRegistry([cloud_provider()])

    with pytest.raises(ApplicationException) as exc_info:
        registry.resolve(
            profile=untrusted_selection,  # type: ignore[arg-type]
            capabilities=capabilities(),
        )

    assert exc_info.value.code == "invalid_llm_provider_profile"


def test_registry_resolution_does_not_generate_or_call_models() -> None:
    cloud = cloud_provider()
    local = local_provider()
    registry = LLMProviderRegistry([cloud, local])

    registry.resolve(
        profile=ProcessingProfile.CLOUD,
        capabilities=capabilities(),
    )
    registry.resolve(
        profile=ProcessingProfile.HYBRID_LOCAL,
        capabilities=capabilities(),
    )

    assert cloud.stream_calls == 0
    assert local.stream_calls == 0
