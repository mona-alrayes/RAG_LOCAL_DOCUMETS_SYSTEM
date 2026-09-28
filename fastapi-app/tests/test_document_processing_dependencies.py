import pytest

from app.api.dependencies import _build_profile_registry
from app.core.config import DeploymentMode, Settings
from app.core.exceptions import ApplicationException
from app.processing.base import ProcessingProfile
from app.runtime.models import (
    LocalRuntimeSnapshot,
    ResourceSnapshot,
    RuntimeBackend,
    RuntimeDtype,
    RuntimeProbeStatus,
)
from app.runtime.state import (
    local_model_coordinator_state,
    local_runtime_state,
)


@pytest.fixture(autouse=True)
def clear_local_runtime_state():
    local_runtime_state.clear()
    local_model_coordinator_state.clear()

    yield

    local_runtime_state.clear()
    local_model_coordinator_state.clear()


def test_cloud_deployment_registers_only_cloud_profile() -> None:
    settings = Settings(
        _env_file=None,
        rag_deployment_mode=DeploymentMode.CLOUD,
    )

    registry = _build_profile_registry(settings)

    cloud = registry.resolve(ProcessingProfile.CLOUD)

    assert cloud.profile is ProcessingProfile.CLOUD

    with pytest.raises(ApplicationException) as exc_info:
        registry.resolve(ProcessingProfile.HYBRID_LOCAL)

    assert exc_info.value.code == "processing_profile_not_registered"


def test_local_profile_is_not_registered_when_runtime_is_unavailable() -> None:
    settings = Settings(
        _env_file=None,
        rag_deployment_mode=DeploymentMode.LOCAL,
    )

    registry = _build_profile_registry(settings)

    assert (
        registry.resolve(ProcessingProfile.CLOUD).profile
        is ProcessingProfile.CLOUD
    )

    with pytest.raises(ApplicationException) as exc_info:
        registry.resolve(ProcessingProfile.HYBRID_LOCAL)

    assert exc_info.value.code == "processing_profile_not_registered"


def test_local_profile_is_registered_when_runtime_is_ready() -> None:
    settings = Settings(
        _env_file=None,
        rag_deployment_mode=DeploymentMode.LOCAL,
    )

    local_runtime_state.set(
        LocalRuntimeSnapshot(
            ready=True,
            requested_device="cpu",
            selected_backend=RuntimeBackend.CPU,
            selected_dtype=RuntimeDtype.FP32,
            probe_status=RuntimeProbeStatus.PASSED,
            failure_reason=None,
            resources=ResourceSnapshot(),
        )
    )

    local_model_coordinator_state.set(
        object()  # type: ignore[arg-type]
    )

    registry = _build_profile_registry(settings)

    local = registry.resolve(
        ProcessingProfile.HYBRID_LOCAL
    )

    assert local.profile is ProcessingProfile.HYBRID_LOCAL
