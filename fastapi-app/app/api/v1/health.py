from fastapi import APIRouter

from app.runtime.state import local_runtime_state
from app.schemas.health import HealthResponse
from app.schemas.runtime import LocalRuntimeCapability


router = APIRouter(prefix="/api/v1")


@router.get(
    "/health",
    response_model=HealthResponse,
)
async def health() -> HealthResponse:
    snapshot = local_runtime_state.get()

    if snapshot is None:
        return HealthResponse(
            status="ok",
            local_runtime=None,
        )

    return HealthResponse(
        status="ok",
        local_runtime=LocalRuntimeCapability(
            ready=snapshot.ready,
            requested_device=snapshot.requested_device,
            selected_backend=snapshot.selected_backend,
            selected_dtype=snapshot.selected_dtype,
            probe_status=snapshot.probe_status,
            failure_reason=snapshot.failure_reason,
        ),
    )
