from typing import Literal

from pydantic import BaseModel

from app.schemas.runtime import LocalRuntimeCapability


class HealthResponse(BaseModel):
    status: Literal["ok"] = "ok"
    local_runtime: LocalRuntimeCapability | None = None
