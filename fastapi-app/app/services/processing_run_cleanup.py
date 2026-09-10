from qdrant_client import QdrantClient

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.persistence import (
    PointScope,
    count_points,
    delete_points,
)
from app.processing.indexing import resolve_qdrant_collection
from app.schemas.documents import (
    DeleteProcessingRunPointsRequest,
    DeleteProcessingRunPointsResponse,
)


class ProcessingRunPointsCleanupService:
    def __init__(
        self,
        *,
        settings: Settings,
        client: QdrantClient,
    ) -> None:
        self._settings = settings
        self._client = client

    def delete(
        self,
        *,
        request: DeleteProcessingRunPointsRequest,
    ) -> DeleteProcessingRunPointsResponse:
        collection_name = resolve_qdrant_collection(
            profile=request.processing_profile,
            settings=self._settings,
        )

        scope = PointScope(
            user_id=request.user_id,
            document_id=request.document_id,
            processing_run_id=request.processing_run_id,
        )

        try:
            delete_points(
                client=self._client,
                collection_name=collection_name,
                scope=scope,
            )

            remaining_count = count_points(
                client=self._client,
                collection_name=collection_name,
                scope=scope,
            )
        except Exception as exc:
            raise ApplicationException(
                code="qdrant_cleanup_failed",
                message="Processing run point cleanup failed.",
            ) from exc

        if remaining_count != 0:
            raise ApplicationException(
                code="qdrant_cleanup_count_mismatch",
                message="Processing run points remain after cleanup.",
            )

        return DeleteProcessingRunPointsResponse(
            document_id=request.document_id,
            processing_run_id=request.processing_run_id,
            status="deleted",
        )
