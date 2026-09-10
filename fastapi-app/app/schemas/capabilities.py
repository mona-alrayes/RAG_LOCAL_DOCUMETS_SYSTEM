from enum import StrEnum

from pydantic import BaseModel

from app.core.config import DeploymentMode
from app.schemas.runtime import LocalRuntimeCapability


class ProviderStatus(StrEnum):
    AVAILABLE = "available"
    UNAVAILABLE = "unavailable"
    NOT_CHECKED = "not_checked"


class ProviderCapability(BaseModel):
    provider: str
    status: ProviderStatus


class DeploymentCapabilitiesResponse(BaseModel):
    deployment_mode: DeploymentMode
    supported_profiles: list[str]
    available_profiles: list[str]
    providers: list[ProviderCapability]
    local_runtime: LocalRuntimeCapability | None = None
