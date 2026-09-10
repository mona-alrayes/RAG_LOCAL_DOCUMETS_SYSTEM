from enum import StrEnum
from typing import Literal

from pydantic import BaseModel, Field, NonNegativeInt, PositiveInt

from app.processing.base import ProcessingProfile
from app.processing.reporting import (
    ProcessingProfileSnapshot,
    ProcessingStage,
    ProcessingWarning,
)


class DocumentFileType(StrEnum):
    PDF = "pdf"
    DOCX = "docx"
    TXT = "txt"


class ProcessDocumentRequest(BaseModel):
    user_id: PositiveInt
    document_id: PositiveInt
    processing_run_id: PositiveInt
    processing_profile: ProcessingProfile
    file_type: DocumentFileType


class ProcessDocumentResponse(BaseModel):
    document_id: PositiveInt
    processing_run_id: PositiveInt
    profile: ProcessingProfile
    status: Literal["indexed"]
    qdrant_collection: str = Field(min_length=1)
    profile_snapshot: ProcessingProfileSnapshot
    total_pages: NonNegativeInt | None
    total_chunks: NonNegativeInt
    vector_count: NonNegativeInt
    vector_dimension: PositiveInt | None
    stage_timings_ms: dict[ProcessingStage, NonNegativeInt]
    warnings: list[ProcessingWarning]


class DeleteProcessingRunPointsRequest(BaseModel):
    user_id: PositiveInt
    document_id: PositiveInt
    processing_run_id: PositiveInt
    processing_profile: ProcessingProfile


class DeleteProcessingRunPointsResponse(BaseModel):
    document_id: PositiveInt
    processing_run_id: PositiveInt
    status: Literal["deleted"]
