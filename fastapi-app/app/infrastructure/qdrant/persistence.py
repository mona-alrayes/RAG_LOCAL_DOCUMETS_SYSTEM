from dataclasses import dataclass
from typing import Sequence

from qdrant_client import QdrantClient, models


@dataclass(frozen=True, slots=True)
class PointScope:
    user_id: int
    document_id: int
    processing_run_id: int


def build_point_scope_filter(scope: PointScope) -> models.Filter:
    return models.Filter(
        must=[
            models.FieldCondition(
                key="user_id",
                match=models.MatchValue(value=scope.user_id),
            ),
            models.FieldCondition(
                key="document_id",
                match=models.MatchValue(value=scope.document_id),
            ),
            models.FieldCondition(
                key="processing_run_id",
                match=models.MatchValue(value=scope.processing_run_id),
            ),
        ]
    )


def upsert_points(
    *,
    client: QdrantClient,
    collection_name: str,
    points: Sequence[models.PointStruct],
) -> models.UpdateResult:
    return client.upsert(
        collection_name=collection_name,
        points=points,
        wait=True,
    )


def count_points(
    *,
    client: QdrantClient,
    collection_name: str,
    scope: PointScope,
) -> int:
    result = client.count(
        collection_name=collection_name,
        count_filter=build_point_scope_filter(scope),
        exact=True,
    )

    return result.count


def delete_points(
    *,
    client: QdrantClient,
    collection_name: str,
    scope: PointScope,
) -> models.UpdateResult:
    return client.delete(
        collection_name=collection_name,
        points_selector=models.FilterSelector(
            filter=build_point_scope_filter(scope),
        ),
        wait=True,
    )
