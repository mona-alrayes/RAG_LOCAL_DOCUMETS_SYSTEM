from typing import Annotated

from fastapi import APIRouter, Depends, File, Form, UploadFile
from pydantic import PositiveInt
from starlette.concurrency import run_in_threadpool

from app.api.dependencies import (
    get_process_document_service,
    get_processing_run_points_cleanup_service,
)
from app.infrastructure.files.temporary_document import (
    temporary_document,
)
from app.processing.base import ProcessingProfile
from app.schemas.documents import (
    DeleteProcessingRunPointsRequest,
    DeleteProcessingRunPointsResponse,
    DocumentFileType,
    ProcessDocumentRequest,
    ProcessDocumentResponse,
)
from app.services.document_processing import ProcessDocumentService
from app.services.processing_run_cleanup import (
    ProcessingRunPointsCleanupService,
)


router = APIRouter(prefix="/api/v1")


_FILE_SUFFIXES = {
    DocumentFileType.PDF: ".pdf",
    DocumentFileType.DOCX: ".docx",
    DocumentFileType.TXT: ".txt",
}


def parse_process_document_request(
    user_id: Annotated[PositiveInt, Form()],
    document_id: Annotated[PositiveInt, Form()],
    processing_run_id: Annotated[PositiveInt, Form()],
    processing_profile: Annotated[ProcessingProfile, Form()],
    file_type: Annotated[DocumentFileType, Form()],
) -> ProcessDocumentRequest:
    return ProcessDocumentRequest(
        user_id=user_id,
        document_id=document_id,
        processing_run_id=processing_run_id,
        processing_profile=processing_profile,
        file_type=file_type,
    )


@router.post(
    "/documents/process",
    response_model=ProcessDocumentResponse,
)
async def process_document(
    metadata: Annotated[
        ProcessDocumentRequest,
        Depends(parse_process_document_request),
    ],
    file: Annotated[
        UploadFile,
        File(),
    ],
    service: Annotated[
        ProcessDocumentService,
        Depends(get_process_document_service),
    ],
) -> ProcessDocumentResponse:
    source = _safe_source_name(
        filename=file.filename,
        file_type=metadata.file_type,
    )

    try:
        with temporary_document(
            file.file,
            suffix=_FILE_SUFFIXES[metadata.file_type],
        ) as file_path:
            return await run_in_threadpool(
                service.process,
                request=metadata,
                file_path=file_path,
                source=source,
            )
    finally:
        await file.close()


@router.delete(
    "/documents/processing-runs/points",
    response_model=DeleteProcessingRunPointsResponse,
)
def delete_processing_run_points(
    request: DeleteProcessingRunPointsRequest,
    service: Annotated[
        ProcessingRunPointsCleanupService,
        Depends(get_processing_run_points_cleanup_service),
    ],
) -> DeleteProcessingRunPointsResponse:
    return service.delete(request=request)


def _safe_source_name(
    *,
    filename: str | None,
    file_type: DocumentFileType,
) -> str:
    if filename:
        normalized = filename.replace("\\", "/")
        basename = normalized.rsplit("/", maxsplit=1)[-1].strip()

        safe_name = "".join(
            character
            for character in basename
            if ord(character) >= 32
            and character not in {"/", "\\"}
        ).strip()

        if safe_name:
            return safe_name

    return f"document.{file_type.value}"
