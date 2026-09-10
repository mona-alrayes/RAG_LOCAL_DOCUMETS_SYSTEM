from collections.abc import Iterable

from app.core.exceptions import ApplicationException
from app.generation.base import LLMProvider
from app.processing.base import ProcessingProfile
from app.schemas.capabilities import (
    DeploymentCapabilitiesResponse,
    ProviderStatus,
)

_EXPECTED_CAPABILITY_BY_PROFILE: dict[ProcessingProfile, str] = {
    ProcessingProfile.CLOUD: "hugging_face_llm",
    ProcessingProfile.HYBRID_LOCAL: "ollama_llm",
}


class LLMProviderRegistry:
    def __init__(self, providers: Iterable[LLMProvider]) -> None:
        self._providers: dict[ProcessingProfile, LLMProvider] = {}

        for provider in providers:
            profile = provider.profile

            if not isinstance(profile, ProcessingProfile):
                raise ApplicationException(
                    code="invalid_llm_provider_profile",
                    message=(
                        "LLM provider registration requires a trusted "
                        "ProcessingProfile value."
                    ),
                )

            expected_capability = _EXPECTED_CAPABILITY_BY_PROFILE[profile]

            if provider.capability_name != expected_capability:
                raise ApplicationException(
                    code="llm_provider_profile_mapping_invalid",
                    message=(
                        "LLM provider registration does not match the "
                        "trusted provider mapping for processing profile "
                        f"'{profile.value}'."
                    ),
                )

            if profile in self._providers:
                raise ApplicationException(
                    code="llm_provider_profile_already_registered",
                    message=(
                        "An LLM provider is already registered for "
                        f"processing profile '{profile.value}'."
                    ),
                )

            self._providers[profile] = provider

    def resolve(
        self,
        *,
        profile: ProcessingProfile,
        capabilities: DeploymentCapabilitiesResponse,
    ) -> LLMProvider:
        if not isinstance(profile, ProcessingProfile):
            raise ApplicationException(
                code="invalid_llm_provider_profile",
                message=(
                    "LLM provider selection requires a trusted "
                    "ProcessingProfile value."
                ),
            )

        try:
            provider = self._providers[profile]
        except KeyError:
            raise ApplicationException(
                code="llm_provider_not_registered",
                message=(
                    "No LLM provider is registered for processing "
                    f"profile '{profile.value}'."
                ),
            ) from None

        matching_statuses = [
            capability.status
            for capability in capabilities.providers
            if capability.provider == provider.capability_name
        ]

        if matching_statuses != [ProviderStatus.AVAILABLE]:
            raise ApplicationException(
                code="llm_provider_unavailable",
                message=(
                    "The LLM provider for processing profile "
                    f"'{profile.value}' is unavailable."
                ),
            )

        return provider
