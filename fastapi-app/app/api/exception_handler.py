import logging

from fastapi import Request, status
from fastapi.responses import JSONResponse

from app.core.exceptions import ApplicationException
from app.core.logging import get_correlation_id
from app.schemas.errors import ErrorDetail, ErrorResponse


logger = logging.getLogger("app.exception")


async def application_exception_handler(
    _request: Request,
    exc: ApplicationException,
) -> JSONResponse:
    correlation_id = get_correlation_id() or "unknown"

    logger.error(
        "Application exception: %s",
        exc.code,
        exc_info=(type(exc), exc, exc.__traceback__),
    )

    response = ErrorResponse(
        error=ErrorDetail(
            code=exc.code,
            message=exc.message,
        ),
        correlation_id=correlation_id,
    )

    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content=response.model_dump(),
    )
