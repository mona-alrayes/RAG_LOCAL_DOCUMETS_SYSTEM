from collections.abc import AsyncIterator

import pytest

import app.generation.composition as composition_module
from app.core.config import DeploymentMode, Settings
from app.core.exceptions import ApplicationException
from app.generation.base import GenerationOptions, LLMProvider
from app.generation.composition import resolve_llm_provider
from app.generation.registry import LLMProviderRegistry
from app.processing.base import ProcessingProfile
from app.runtime.state import local_runtime_state
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
        async def generate() -> AsyncIterator[str]:
            yield "unused"

        return generate()


@pytest.fixture(autouse=True)
def clear_local_runtime_state():
    local_runtime_state.clear()

    yield

    local_runtime_state.clear()


def test_registry_rejects_provider_that_violates_trusted_profile_mapping() -> None:
    provider = FakeLLMProvider(
        profile=ProcessingProfile.CLOUD,
        capability_name="ollama_llm",
    )

    with pytest.raises(ApplicationException) as exc_info:
        LLMProviderRegistry([provider])

    assert (
        exc_info.value.code
        == "llm_provider_profile_mapping_invalid"
    )


def test_cloud_resolution_builds_only_hugging_face_provider(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    calls: list[str] = []
    cloud = FakeLLMProvider(
        profile=ProcessingProfile.CLOUD,
        capability_name="hugging_face_llm",
    )

    def build_cloud(settings: Settings) -> LLMProvider:
        calls.append("hugging_face")
        return cloud

    def build_local(settings: Settings) -> LLMProvider:
        calls.append("ollama")
        raise AssertionError("Ollama must not be built for cloud.")

    monkeypatch.setattr(
        composition_module,
        "build_hugging_face_llm_provider",
        build_cloud,
    )
    monkeypatch.setattr(
        composition_module,
        "build_ollama_llm_provider",
        build_local,
    )

    settings = Settings(
        _env_file=None,
        rag_deployment_mode=DeploymentMode.CLOUD,
        hf_token="hf-secret",
        llama_cloud_api_key=None,
        jinaai_api_key="",
    )

    resolved = resolve_llm_provider(
        profile=ProcessingProfile.CLOUD,
        settings=settings,
    )

    assert resolved is cloud
    assert calls == ["hugging_face"]


def test_hybrid_local_resolution_builds_only_ollama_provider(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    calls: list[str] = []
    local = FakeLLMProvider(
        profile=ProcessingProfile.HYBRID_LOCAL,
        capability_name="ollama_llm",
    )

    def build_cloud(settings: Settings) -> LLMProvider:
        calls.append("hugging_face")
        raise AssertionError(
            "Hugging Face must not be built for hybrid local."
        )

    def build_local(settings: Settings) -> LLMProvider:
        calls.append("ollama")
        return local

    monkeypatch.setattr(
        composition_module,
        "build_hugging_face_llm_provider",
        build_cloud,
    )
    monkeypatch.setattr(
        composition_module,
        "build_ollama_llm_provider",
        build_local,
    )

    settings = Settings(
        _env_file=None,
        rag_deployment_mode=DeploymentMode.LOCAL,
        hf_token=None,
        ollama_base_url="http://127.0.0.1:11434",
        local_llm_model="qwen3.5:4b",
        llama_cloud_api_key=None,
    )

    resolved = resolve_llm_provider(
        profile=ProcessingProfile.HYBRID_LOCAL,
        settings=settings,
    )

    assert resolved is local
    assert calls == ["ollama"]


def test_cloud_missing_configuration_fails_without_ollama_fallback(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    ollama_calls = 0

    def build_local(settings: Settings) -> LLMProvider:
        nonlocal ollama_calls
        ollama_calls += 1
        raise AssertionError("Ollama fallback is forbidden.")

    monkeypatch.setattr(
        composition_module,
        "build_ollama_llm_provider",
        build_local,
    )

    settings = Settings(
        _env_file=None,
        rag_deployment_mode=DeploymentMode.CLOUD,
        hf_token=None,
    )

    with pytest.raises(ApplicationException) as exc_info:
        resolve_llm_provider(
            profile=ProcessingProfile.CLOUD,
            settings=settings,
        )

    assert (
        exc_info.value.code
        == "hugging_face_llm_not_configured"
    )
    assert ollama_calls == 0


def test_hybrid_local_missing_configuration_fails_without_cloud_fallback(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    cloud_calls = 0

    def build_cloud(settings: Settings) -> LLMProvider:
        nonlocal cloud_calls
        cloud_calls += 1
        raise AssertionError("Cloud fallback is forbidden.")

    monkeypatch.setattr(
        composition_module,
        "build_hugging_face_llm_provider",
        build_cloud,
    )

    settings = Settings(
        _env_file=None,
        rag_deployment_mode=DeploymentMode.LOCAL,
        hf_token="hf-secret",
        ollama_base_url=" ",
        local_llm_model="qwen3.5:4b",
    )

    with pytest.raises(ApplicationException) as exc_info:
        resolve_llm_provider(
            profile=ProcessingProfile.HYBRID_LOCAL,
            settings=settings,
        )

    assert exc_info.value.code == "ollama_llm_not_configured"
    assert cloud_calls == 0


def test_hybrid_local_is_rejected_when_deployment_capability_is_absent(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    cloud_calls = 0

    def build_cloud(settings: Settings) -> LLMProvider:
        nonlocal cloud_calls
        cloud_calls += 1
        raise AssertionError("Cloud fallback is forbidden.")

    monkeypatch.setattr(
        composition_module,
        "build_hugging_face_llm_provider",
        build_cloud,
    )

    settings = Settings(
        _env_file=None,
        rag_deployment_mode=DeploymentMode.CLOUD,
        ollama_base_url="http://127.0.0.1:11434",
        local_llm_model="qwen3.5:4b",
    )

    with pytest.raises(ApplicationException) as exc_info:
        resolve_llm_provider(
            profile=ProcessingProfile.HYBRID_LOCAL,
            settings=settings,
        )

    assert exc_info.value.code == "llm_provider_unavailable"
    assert cloud_calls == 0


@pytest.mark.parametrize(
    "untrusted_profile",
    ["cloud", "hugging_face_llm", "ollama_llm"],
)
def test_composition_rejects_untrusted_free_string_selection(
    untrusted_profile: str,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    build_calls = 0

    def forbidden_builder(settings: Settings) -> LLMProvider:
        nonlocal build_calls
        build_calls += 1
        raise AssertionError("No provider should be built.")

    monkeypatch.setattr(
        composition_module,
        "build_hugging_face_llm_provider",
        forbidden_builder,
    )
    monkeypatch.setattr(
        composition_module,
        "build_ollama_llm_provider",
        forbidden_builder,
    )

    settings = Settings(_env_file=None)

    with pytest.raises(ApplicationException) as exc_info:
        resolve_llm_provider(
            profile=untrusted_profile,  # type: ignore[arg-type]
            settings=settings,
        )

    assert exc_info.value.code == "invalid_llm_provider_profile"
    assert build_calls == 0
