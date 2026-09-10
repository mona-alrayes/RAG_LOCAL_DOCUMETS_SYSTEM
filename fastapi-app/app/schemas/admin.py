from pydantic import BaseModel, ConfigDict, Field, PositiveInt

from app.processing.base import ProcessingProfile


class AdminChunksRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    user_id: PositiveInt
    document_id: PositiveInt
    processing_run_id: PositiveInt
    processing_profile: ProcessingProfile
    limit: int = Field(default=25, ge=1, le=100)
    cursor: int | None = Field(default=None, ge=0, strict=True)


class AdminChunk(BaseModel):
    model_config = ConfigDict(extra="forbid")
    point_id: str
    chunk_index: int = Field(ge=0)
    text: str
    source: str
    page: int | None = None
    section: str | None = None


class AdminChunksResponse(BaseModel):
    document_id: int
    processing_run_id: int
    processing_profile: ProcessingProfile
    total: int
    chunks: list[AdminChunk]
    next_cursor: int | None
