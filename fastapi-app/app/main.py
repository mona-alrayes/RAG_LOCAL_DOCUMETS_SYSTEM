from collections.abc import AsyncIterator
from contextlib import asynccontextmanager, nullcontext

from fastapi import FastAPI
from starlette.concurrency import run_in_threadpool

from app.api.exception_handler import application_exception_handler
from app.api.v1.admin_routes import router as admin_router
from app.api.v1.capabilities_routes import router as capabilities_router
from app.api.v1.document_processing_routes import (
    router as document_processing_router,
)
from app.api.v1.health import router as health_router
from app.api.v1.rag_routes import router as rag_router
from app.core.config import (
    DeploymentMode,
    get_settings,
    validate_startup_configuration,
)
from app.core.exceptions import ApplicationException
from app.core.logging import configure_logging
from app.infrastructure.qdrant.startup import initialize_qdrant
from app.middleware.correlation_id import CorrelationIdMiddleware
from app.middleware.internal_api_auth import InternalApiAuthMiddleware
from app.runtime.process_guard import LocalProcessGuard
from app.runtime.startup import initialize_local_runtime
from app.runtime.state import local_model_coordinator_state, local_runtime_state


def create_app() -> FastAPI:
    settings = get_settings()

    configure_logging()

    @asynccontextmanager
    async def lifespan(_: FastAPI) -> AsyncIterator[None]:
        validate_startup_configuration(settings)
        guard = (
            LocalProcessGuard()
            if settings.rag_deployment_mode is DeploymentMode.LOCAL
            else nullcontext()
        )
        with guard:
            initialize_qdrant(settings)
            initialize_local_runtime(settings)
            try:
                yield
            finally:
                coordinator = local_model_coordinator_state.get()
                try:
                    if coordinator is not None:
                        await run_in_threadpool(coordinator.shutdown)
                finally:
                    local_model_coordinator_state.clear()
                    local_runtime_state.clear()

    app = FastAPI(
        title=settings.app_name,
        version=settings.app_version,
        lifespan=lifespan,
    )

    app.add_exception_handler(
        ApplicationException,
        application_exception_handler,
    )

    app.include_router(admin_router)
    app.include_router(health_router)
    app.include_router(capabilities_router)
    app.include_router(document_processing_router)
    app.include_router(rag_router)
    app.add_middleware(
        InternalApiAuthMiddleware,
        internal_api_key=settings.internal_api_key,
    )
    app.add_middleware(CorrelationIdMiddleware)

    return app


app = create_app()
