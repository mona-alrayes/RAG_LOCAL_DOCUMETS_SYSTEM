from unittest.mock import MagicMock

import pytest
from qdrant_client import QdrantClient, models

from app.infrastructure.qdrant.persistence import (
    PointScope,
    count_points,
    delete_points,
    upsert_points,
)


@pytest.mark.parametrize(
    "collection_name",
    [
        "rag_documents_cloud",
        "rag_documents_hybrid_local",
    ],
)
def test_upsert_forwards_existing_points_unchanged(
    collection_name: str,
) -> None:
    client = MagicMock(spec=QdrantClient)
    point = models.PointStruct(
        id="123e4567-e89b-12d3-a456-426614174000",
        vector={},
        payload={},
    )

    upsert_points(
        client=client,
        collection_name=collection_name,
        points=[point],
    )

    client.upsert.assert_called_once_with(
        collection_name=collection_name,
        points=[point],
        wait=True,
    )


def test_count_uses_processing_run_scope() -> None:
    client = MagicMock(spec=QdrantClient)
    client.count.return_value = models.CountResult(count=8)

    count = count_points(
        client=client,
        collection_name="rag_documents_cloud",
        scope=PointScope(
            user_id=7,
            document_id=12,
            processing_run_id=81,
        ),
    )

    assert count == 8

    count_filter = client.count.call_args.kwargs["count_filter"]

    assert {
        condition.key: condition.match.value
        for condition in count_filter.must
    } == {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
    }

    assert client.count.call_args.kwargs["exact"] is True


def test_delete_uses_processing_run_scope() -> None:
    client = MagicMock(spec=QdrantClient)

    delete_points(
        client=client,
        collection_name="rag_documents_cloud",
        scope=PointScope(
            user_id=7,
            document_id=12,
            processing_run_id=81,
        ),
    )

    selector = client.delete.call_args.kwargs["points_selector"]

    assert isinstance(selector, models.FilterSelector)
    assert {
        condition.key: condition.match.value
        for condition in selector.filter.must
    } == {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
    }
