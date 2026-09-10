from qdrant_client import QdrantClient, models

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.persistence import PointScope, build_point_scope_filter
from app.processing.indexing import resolve_qdrant_collection
from app.schemas.admin import AdminChunk, AdminChunksRequest, AdminChunksResponse


class AdminChunksService:
    def __init__(self, *, settings: Settings, client: QdrantClient):
        self.settings = settings
        self.client = client

    def read(self, request: AdminChunksRequest) -> AdminChunksResponse:
        collection = resolve_qdrant_collection(
            profile=request.processing_profile, settings=self.settings
        )
        scope = build_point_scope_filter(
            PointScope(request.user_id, request.document_id, request.processing_run_id)
        )
        scope.must.append(
            models.FieldCondition(
                key="processing_profile",
                match=models.MatchValue(value=request.processing_profile.value),
            )
        )
        try:
            total = self.client.count(collection, count_filter=scope, exact=True).count
            points, _ = self.client.scroll(
                collection,
                scroll_filter=scope,
                limit=request.limit + 1,
                order_by=models.OrderBy(
                    key="chunk_index",
                    direction=models.Direction.ASC,
                    start_from=request.cursor if request.cursor is not None else 0,
                ),
                with_vectors=False,
                with_payload=["chunk_index", "text", "source", "page", "section"],
            )
            chunks = [
                AdminChunk(
                    point_id=str(point.id),
                    **{
                        key: value
                        for key, value in (point.payload or {}).items()
                        if key in {"chunk_index", "text", "source", "page", "section"}
                    },
                )
                for point in points[: request.limit]
            ]
        except Exception as exc:
            raise ApplicationException(
                code="admin_chunks_unavailable",
                message="Unable to read indexed chunks.",
            ) from exc
        return AdminChunksResponse(
            document_id=request.document_id,
            processing_run_id=request.processing_run_id,
            processing_profile=request.processing_profile,
            total=total,
            chunks=chunks,
            next_cursor=(
                points[request.limit].payload["chunk_index"]
                if len(points) > request.limit
                else None
            ),
        )
