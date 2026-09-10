from pydantic import BaseModel

from app.runtime.models import (
    RuntimeBackend,
    RuntimeDtype,
    RuntimeProbeStatus,
)


class LocalRuntimeCapability(BaseModel):
    ready: bool
    requested_device: str
    selected_backend: RuntimeBackend | None
    selected_dtype: RuntimeDtype | None
    probe_status: RuntimeProbeStatus
    failure_reason: str | None
