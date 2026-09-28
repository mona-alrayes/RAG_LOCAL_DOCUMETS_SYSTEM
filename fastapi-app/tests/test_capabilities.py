import pytest
from fastapi.testclient import TestClient

from app.core.config import Settings, get_settings
from app.main import create_app
from app.runtime.models import (
    LocalRuntimeSnapshot,
    ResourceSnapshot,
    RuntimeBackend,
    RuntimeDtype,
    RuntimeProbeStatus,
)
from app.runtime.state import local_runtime_state
from app.services.capabilities import CapabilitiesService

TEST_API_KEY = "h12-test-internal-key"


def ready_runtime() -> LocalRuntimeSnapshot:
    return LocalRuntimeSnapshot(
        ready=True,
        requested_device="cpu",
        selected_backend=RuntimeBackend.CPU,
        selected_dtype=RuntimeDtype.FP32,
        probe_status=RuntimeProbeStatus.PASSED,
        failure_reason=None,
        resources=ResourceSnapshot(),
    )


def unavailable_runtime() -> LocalRuntimeSnapshot:
    return LocalRuntimeSnapshot(
        ready=False,
        requested_device="cpu",
        selected_backend=None,
        selected_dtype=None,
        probe_status=RuntimeProbeStatus.FAILED,
        failure_reason="Local runtime is unavailable.",
        resources=ResourceSnapshot(),
    )


def test_cloud_profile_is_available_when_required_credentials_exist() -> None:
    settings = Settings(
        rag_deployment_mode="cloud",
        internal_api_key=TEST_API_KEY,
        llama_cloud_api_key="test-llama-key",
        jinaai_api_key="test-jina-key",
        hf_token="test-hf-key",
    )

    result = CapabilitiesService().build(settings)

    assert result.supported_profiles == ["cloud"]
    assert result.available_profiles == ["cloud"]
    assert result.local_runtime is None

    provider_statuses = {
        provider.provider: provider.status.value
        for provider in result.providers
    }

    assert provider_statuses["llama_parse"] == "available"
    assert provider_statuses["jina_embeddings"] == "available"
    assert provider_statuses["jina_reranker"] == "available"
    assert provider_statuses["hugging_face_llm"] == "available"


def test_cloud_profile_is_unavailable_when_required_credential_is_missing() -> None:
    settings = Settings(
        rag_deployment_mode="cloud",
        internal_api_key=TEST_API_KEY,
        llama_cloud_api_key="test-llama-key",
        jinaai_api_key="",
    )

    result = CapabilitiesService().build(settings)

    assert result.supported_profiles == ["cloud"]
    assert result.available_profiles == []


def test_hugging_face_llm_is_unavailable_without_hf_token() -> None:
    settings = Settings(
        rag_deployment_mode="cloud",
        internal_api_key=TEST_API_KEY,
        llama_cloud_api_key="test-llama-key",
        jinaai_api_key="test-jina-key",
        hf_token="",
    )

    result = CapabilitiesService().build(settings)

    provider_statuses = {
        provider.provider: provider.status.value
        for provider in result.providers
    }

    assert provider_statuses["hugging_face_llm"] == "unavailable"

    # LLM availability must not change document-processing
    # profile availability.
    assert result.available_profiles == ["cloud"]


def test_local_deployment_exposes_both_profiles_when_runtime_is_ready() -> None:
    settings = Settings(
        rag_deployment_mode="local",
        local_ai_topology="host_native",
        internal_api_key=TEST_API_KEY,
        llama_cloud_api_key="test-llama-key",
        jinaai_api_key="test-jina-key",
    )

    result = CapabilitiesService().build(
        settings,
        ready_runtime(),
    )

    assert result.supported_profiles == [
        "cloud",
        "hybrid_local",
    ]

    assert result.available_profiles == [
        "cloud",
        "hybrid_local",
    ]

    assert result.local_runtime is not None
    assert result.local_runtime.ready is True

    provider_statuses = {
        provider.provider: provider.status.value
        for provider in result.providers
    }

    assert provider_statuses["bge_m3_embeddings"] == "available"
    assert provider_statuses["bge_reranker"] == "available"
    assert provider_statuses["ollama_llm"] == "available"


def test_ollama_llm_is_unavailable_when_configuration_is_blank() -> None:
    settings = Settings(
        rag_deployment_mode="local",
        local_ai_topology="host_native",
        internal_api_key=TEST_API_KEY,
        llama_cloud_api_key="test-llama-key",
        jinaai_api_key="test-jina-key",
        ollama_base_url="",
        local_llm_model="qwen3.5:4b",
    )

    result = CapabilitiesService().build(
        settings,
        ready_runtime(),
    )

    provider_statuses = {
        provider.provider: provider.status.value
        for provider in result.providers
    }

    assert provider_statuses["ollama_llm"] == "unavailable"

    # LLM availability must not change document-processing
    # profile availability.
    assert result.available_profiles == [
        "cloud",
        "hybrid_local",
    ]


def test_hybrid_local_is_unavailable_when_runtime_is_not_ready() -> None:
    settings = Settings(
        rag_deployment_mode="local",
        local_ai_topology="host_native",
        internal_api_key=TEST_API_KEY,
        llama_cloud_api_key="test-llama-key",
        jinaai_api_key="test-jina-key",
    )

    result = CapabilitiesService().build(
        settings,
        unavailable_runtime(),
    )

    assert result.supported_profiles == [
        "cloud",
        "hybrid_local",
    ]

    assert result.available_profiles == ["cloud"]

    assert result.local_runtime is not None
    assert result.local_runtime.ready is False


def test_capabilities_endpoint_uses_capabilities_service_contract(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("INTERNAL_API_KEY", TEST_API_KEY)
    monkeypatch.setenv("RAG_DEPLOYMENT_MODE", "cloud")
    get_settings.cache_clear()
    local_runtime_state.clear()

    app = create_app()

    settings = Settings(
        rag_deployment_mode="cloud",
        internal_api_key=TEST_API_KEY,
        llama_cloud_api_key="test-llama-key",
        jinaai_api_key="test-jina-key",
    )

    app.dependency_overrides[get_settings] = lambda: settings

    try:
        client = TestClient(app)

        response = client.get(
            "/api/v1/capabilities",
            headers={"X-Internal-API-Key": TEST_API_KEY},
        )

        assert response.status_code == 200

        payload = response.json()

        assert payload["deployment_mode"] == "cloud"
        assert payload["supported_profiles"] == ["cloud"]
        assert payload["available_profiles"] == ["cloud"]
    finally:
        app.dependency_overrides.clear()
        local_runtime_state.clear()
        get_settings.cache_clear()
