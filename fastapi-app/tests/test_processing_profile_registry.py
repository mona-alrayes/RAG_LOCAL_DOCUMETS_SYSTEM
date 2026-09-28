import pytest

from app.core.exceptions import ApplicationException

from app.processing.base import BaseProcessingProfile, ProcessingProfile
from app.processing.registry import ProcessingProfileRegistry


class FakeCloudProcessingProfile(BaseProcessingProfile):
    @property
    def profile(self) -> ProcessingProfile:
        return ProcessingProfile.CLOUD


def test_processing_profile_contains_only_run_profiles() -> None:
    assert list(ProcessingProfile) == [
        ProcessingProfile.CLOUD,
        ProcessingProfile.HYBRID_LOCAL,
    ]

    assert ProcessingProfile.CLOUD.value == "cloud"
    assert ProcessingProfile.HYBRID_LOCAL.value == "hybrid_local"


def test_processing_profile_contract_can_be_implemented() -> None:
    processing_profile = FakeCloudProcessingProfile()

    assert processing_profile.profile is ProcessingProfile.CLOUD


class FakeHybridLocalProcessingProfile(BaseProcessingProfile):
    @property
    def profile(self) -> ProcessingProfile:
        return ProcessingProfile.HYBRID_LOCAL


def test_registry_resolves_registered_processing_profile() -> None:
    cloud = FakeCloudProcessingProfile()
    hybrid_local = FakeHybridLocalProcessingProfile()

    registry = ProcessingProfileRegistry([cloud, hybrid_local])

    assert registry.resolve(ProcessingProfile.CLOUD) is cloud
    assert registry.resolve(ProcessingProfile.HYBRID_LOCAL) is hybrid_local


def test_registry_rejects_unregistered_processing_profile() -> None:
    registry = ProcessingProfileRegistry([])

    with pytest.raises(ApplicationException) as exc_info:
        registry.resolve(ProcessingProfile.CLOUD)

    assert exc_info.value.code == "processing_profile_not_registered"


def test_registry_rejects_raw_profile_string() -> None:
    cloud = FakeCloudProcessingProfile()
    registry = ProcessingProfileRegistry([cloud])

    with pytest.raises(ApplicationException) as exc_info:
        registry.resolve("cloud")  # type: ignore[arg-type]

    assert exc_info.value.code == "invalid_processing_profile"
