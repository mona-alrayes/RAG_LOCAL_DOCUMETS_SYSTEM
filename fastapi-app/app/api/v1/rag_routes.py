import json
import logging
from collections.abc import AsyncIterator
from typing import Annotated

from fastapi import APIRouter, Depends
from fastapi.responses import StreamingResponse
from starlette.concurrency import run_in_threadpool

from app.api.rag_dependencies import (
    get_rag_query_service,
)
from app.core.exceptions import ApplicationException
from app.schemas.rag import RagQueryRequest
from app.schemas.rag_events import RagErrorEvent
from app.services.rag_query import RagQueryService

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api/v1")


@router.post("/rag/query")
async def rag_query(
    request: RagQueryRequest,
    service: Annotated[
        RagQueryService,
        Depends(get_rag_query_service),
    ],
) -> StreamingResponse:
    prepared = await run_in_threadpool(service.prepare, request)

    async def events() -> AsyncIterator[str]:
        try:
            async for event in service.stream(prepared):
                yield (
                    json.dumps(
                        event.model_dump(
                            mode="json",
                        ),
                        ensure_ascii=False,
                    )
                    + "\n"
                )
        except ApplicationException as exception:
            yield (
                json.dumps(
                    RagErrorEvent(
                        code=exception.code,
                        message=(
                            "AI generation failed."
                        ),
                    ).model_dump(mode="json"),
                    ensure_ascii=False,
                )
                + "\n"
            )
        except Exception:
            logger.exception(
                "Unexpected RAG generation stream failure."
            )

            yield (
                json.dumps(
                    RagErrorEvent(
                        code="rag_generation_failed",
                        message=(
                            "AI generation failed."
                        ),
                    ).model_dump(mode="json"),
                    ensure_ascii=False,
                )
                + "\n"
            )

    return StreamingResponse(
        events(),
        media_type="application/x-ndjson",
    )
