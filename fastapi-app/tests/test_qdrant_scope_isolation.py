import pytest
from qdrant_client import QdrantClient, models

from app.infrastructure.qdrant.persistence import (
    PointScope,
    count_points,
    delete_points,
)


COLLECTION_NAME = "test_scope_isolation"

TARGET_SCOPE = PointScope(
    user_id=7,
    document_id=12,
    processing_run_id=81,
)

OTHER_USER_SCOPE = PointScope(
    user_id=8,
    document_id=12,
    processing_run_id=81,
)

OTHER_DOCUMENT_SCOPE = PointScope(
    user_id=7,
    document_id=13,
    processing_run_id=81,
)

OTHER_RUN_SCOPE = PointScope(
    user_id=7,
    document_id=12,
    processing_run_id=82,
)


def _point(
    point_id: int,
    scope: PointScope,
) -> models.PointStruct:
    return models.PointStruct(
        id=point_id,
        vector={"dense_vector": [1.0]},
        payload={
            "user_id": scope.user_id,
            "document_id": scope.document_id,
            "processing_run_id": scope.processing_run_id,
        },
    )


@pytest.fixture
def qdrant_client() -> QdrantClient:
    client = QdrantClient(":memory:")

    client.create_collection(
        collection_name=COLLECTION_NAME,
        vectors_config={
            "dense_vector": models.VectorParams(
                size=1,
                distance=models.Distance.COSINE,
            ),
        },
    )

    yield client

    client.close()


def test_count_points_does_not_leak_across_scope(
    qdrant_client: QdrantClient,
) -> None:
    qdrant_client.upsert(
        collection_name=COLLECTION_NAME,
        points=[
            _point(1, TARGET_SCOPE),
        ],
        wait=True,
    )

    assert count_points(
        client=qdrant_client,
        collection_name=COLLECTION_NAME,
        scope=TARGET_SCOPE,
    ) == 1

    assert count_points(
        client=qdrant_client,
        collection_name=COLLECTION_NAME,
        scope=OTHER_USER_SCOPE,
    ) == 0

    assert count_points(
        client=qdrant_client,
        collection_name=COLLECTION_NAME,
        scope=OTHER_DOCUMENT_SCOPE,
    ) == 0

    assert count_points(
        client=qdrant_client,
        collection_name=COLLECTION_NAME,
        scope=OTHER_RUN_SCOPE,
    ) == 0


def test_delete_points_only_deletes_exact_scope(
    qdrant_client: QdrantClient,
) -> None:
    qdrant_client.upsert(
        collection_name=COLLECTION_NAME,
        points=[
            _point(1, TARGET_SCOPE),
            _point(2, OTHER_USER_SCOPE),
            _point(3, OTHER_DOCUMENT_SCOPE),
            _point(4, OTHER_RUN_SCOPE),
        ],
        wait=True,
    )

    delete_points(
        client=qdrant_client,
        collection_name=COLLECTION_NAME,
        scope=TARGET_SCOPE,
    )

    assert count_points(
        client=qdrant_client,
        collection_name=COLLECTION_NAME,
        scope=TARGET_SCOPE,
    ) == 0

    assert count_points(
        client=qdrant_client,
        collection_name=COLLECTION_NAME,
        scope=OTHER_USER_SCOPE,
    ) == 1

    assert count_points(
        client=qdrant_client,
        collection_name=COLLECTION_NAME,
        scope=OTHER_DOCUMENT_SCOPE,
    ) == 1

    assert count_points(
        client=qdrant_client,
        collection_name=COLLECTION_NAME,
        scope=OTHER_RUN_SCOPE,
    ) == 1
