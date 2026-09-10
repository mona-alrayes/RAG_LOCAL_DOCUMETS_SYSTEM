from typing import Annotated

from fastapi import APIRouter, Depends

from app.core.config import Settings, get_settings
from app.runtime.state import local_runtime_state
from app.schemas.capabilities import DeploymentCapabilitiesResponse
from app.services.capabilities import CapabilitiesService

router = APIRouter(prefix="/api/v1")

capabilities_service = CapabilitiesService()


@router.get(
    "/capabilities",
    response_model=DeploymentCapabilitiesResponse,
)
def capabilities(
    settings: Annotated[Settings, Depends(get_settings)],
) -> DeploymentCapabilitiesResponse:
    return capabilities_service.build(
        settings,
        local_runtime_state.get(),
    )
