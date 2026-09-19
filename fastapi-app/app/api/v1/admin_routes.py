from typing import Annotated

from fastapi import APIRouter, Depends

from app.core.config import get_settings
from app.infrastructure.qdrant.client import build_qdrant_client
from app.schemas.admin import AdminChunksRequest, AdminChunksResponse
from app.services.admin_chunks import AdminChunksService

router = APIRouter(prefix="/api/v1/admin", tags=["admin"])


def get_admin_chunks_service():
    settings = get_settings()
    client = build_qdrant_client(settings)
    try:
        yield AdminChunksService(settings=settings, client=client)
    finally:
        client.close()


@router.post("/chunks", response_model=AdminChunksResponse)
def chunks(
    request: AdminChunksRequest,
    service: Annotated[AdminChunksService, Depends(get_admin_chunks_service)],
) -> AdminChunksResponse:
    return service.read(request)


from app.api.rag_dependencies import get_rag_query_service
from app.schemas.evaluation import EvaluationQuestionRequest, EvaluationQuestionResponse
from app.services.evaluation import EvaluationService
from app.services.golden_evidence_binding import GoldenEvidenceBinder
from app.services.rag_query import RagQueryService


def get_evaluation_service(
    rag: Annotated[
        RagQueryService,
        Depends(get_rag_query_service),
    ],
):
    settings = get_settings()
    client = build_qdrant_client(settings)

    try:
        yield EvaluationService(
            rag=rag,
            binder=GoldenEvidenceBinder(
                settings=settings,
                client=client,
            ),
            settings=settings,
        )
    finally:
        client.close()


@router.post(
    "/evaluate-question",
    response_model=EvaluationQuestionResponse,
)
async def evaluate_question(
    request: EvaluationQuestionRequest,
    service: Annotated[
        EvaluationService,
        Depends(get_evaluation_service),
    ],
) -> EvaluationQuestionResponse:
    return await service.evaluate(request)
