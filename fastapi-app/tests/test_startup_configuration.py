import os

import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from app.core.config import Settings, StartupConfigurationError, get_settings
from app.main import create_app
from app.runtime.models import ResourceSnapshot, RuntimeBackend
from app.runtime.state import (
    local_model_coordinator_state,
    local_runtime_state,
)

TEST_API_KEY = "d9-test-internal-key"


def clear_configuration() -> None:
    for name in (
        "RAG_DEPLOYMENT_MODE",
        "LOCAL_AI_TOPOLOGY",
        "INTERNAL_API_KEY",
    ):
        os.environ.pop(name, None)

    get_settings.cache_clear()


def test_local_configuration_starts_successfully(
    monkeypatch,
) -> None:
    from app.runtime.telemetry import PsutilResourceTelemetry
    from app.runtime.torch_runtime import TorchRuntimeAdapter

    clear_configuration()

    os.environ["RAG_DEPLOYMENT_MODE"] = "local"
    os.environ["LOCAL_AI_TOPOLOGY"] = "host_native"
    os.environ["INTERNAL_API_KEY"] = TEST_API_KEY
    get_settings.cache_clear()

    monkeypatch.setattr(
        TorchRuntimeAdapter,
        "is_available",
        lambda self, backend: backend is RuntimeBackend.CPU,
    )
    monkeypatch.setattr(
        TorchRuntimeAdapter,
        "probe",
        lambda self, backend, dtype: None,
    )
    monkeypatch.setattr(
        TorchRuntimeAdapter,
        "accelerator_memory",
        lambda self, backend: (None, None),
    )
    monkeypatch.setattr(
        PsutilResourceTelemetry,
        "snapshot",
        lambda self: ResourceSnapshot(
            process_rss_bytes=1_000,
            system_available_memory_bytes=8_000,
            system_total_memory_bytes=10_000,
        ),
    )

    with TestClient(create_app()):
        runtime_snapshot = local_runtime_state.get()
        coordinator = local_model_coordinator_state.get()

        assert runtime_snapshot is not None
        assert runtime_snapshot.ready is True
        assert runtime_snapshot.selected_backend is RuntimeBackend.CPU

        assert coordinator is not None
        assert coordinator.active_model is None


def test_cloud_configuration_starts_successfully() -> None:
    clear_configuration()

    os.environ["RAG_DEPLOYMENT_MODE"] = "cloud"
    os.environ["INTERNAL_API_KEY"] = TEST_API_KEY
    get_settings.cache_clear()

    with TestClient(create_app()):
        assert local_runtime_state.get() is None
        assert local_model_coordinator_state.get() is None


def test_missing_internal_api_key_fails_startup() -> None:
    clear_configuration()

    os.environ["RAG_DEPLOYMENT_MODE"] = "cloud"
    get_settings.cache_clear()

    with pytest.raises(
        StartupConfigurationError,
        match="INTERNAL_API_KEY is required",
    ):
        with TestClient(create_app()):
            pass


def test_cloud_configuration_rejects_local_ai_topology() -> None:
    clear_configuration()

    os.environ["RAG_DEPLOYMENT_MODE"] = "cloud"
    os.environ["LOCAL_AI_TOPOLOGY"] = "host_native"
    os.environ["INTERNAL_API_KEY"] = TEST_API_KEY
    get_settings.cache_clear()

    with pytest.raises(
        StartupConfigurationError,
        match="LOCAL_AI_TOPOLOGY must not be configured",
    ):
        with TestClient(create_app()):
            pass


@pytest.mark.parametrize(
    "field",
    [
        "rag_retrieval_top_k",
        "rag_rrf_candidate_multiplier",
        "rag_rerank_candidate_multiplier",
        "rag_cross_profile_rrf_k",
    ],
)
def test_retrieval_configuration_requires_positive_integers(
    field: str,
) -> None:
    with pytest.raises(ValidationError):
        Settings(
            _env_file=None,
            **{field: 0},
        )


@pytest.fixture(autouse=True)
def isolate_startup_tests_from_project_dotenv(
    tmp_path,
    monkeypatch,
):
    """
    Prevent startup configuration tests from reading the developer's
    project-level .env file.

    These tests must be driven exclusively by the environment variables
    they configure themselves.
    """
    monkeypatch.chdir(tmp_path)

    get_settings.cache_clear()

    yield

    get_settings.cache_clear()
