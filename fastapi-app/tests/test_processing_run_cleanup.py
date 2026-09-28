from unittest.mock import MagicMock

import pytest
from qdrant_client import QdrantClient, models

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.processing.base import ProcessingProfile
from app.schemas.documents import DeleteProcessingRunPointsRequest
from app.services.processing_run_cleanup import (
    ProcessingRunPointsCleanupService,
)


def cleanup_request() -> DeleteProcessingRunPointsRequest:
    return DeleteProcessingRunPointsRequest(
        user_id=7,
        document_id=12,
        processing_run_id=81,
        processing_profile=ProcessingProfile.CLOUD,
    )


def test_cleanup_deletes_and_verifies_the_exact_processing_run_scope() -> None:
    settings = Settings(_env_file=None)
    client = MagicMock(spec=QdrantClient)
    client.count.return_value = models.CountResult(count=0)

    result = ProcessingRunPointsCleanupService(
        settings=settings,
        client=client,
    ).delete(request=cleanup_request())

    assert result.document_id == 12
    assert result.processing_run_id == 81
    assert result.status == "deleted"

    delete_call = client.delete.call_args

    assert (
        delete_call.kwargs["collection_name"]
        == settings.qdrant_cloud_collection
    )
    assert delete_call.kwargs["wait"] is True

    delete_filter = (
        delete_call.kwargs["points_selector"].filter
    )

    assert {
        condition.key: condition.match.value
        for condition in delete_filter.must
    } == {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
    }

    count_call = client.count.call_args

    assert (
        count_call.kwargs["collection_name"]
        == settings.qdrant_cloud_collection
    )
    assert count_call.kwargs["exact"] is True


def test_cleanup_fails_when_processing_run_points_remain() -> None:
    settings = Settings(_env_file=None)
    client = MagicMock(spec=QdrantClient)
    client.count.return_value = models.CountResult(count=1)

    with pytest.raises(ApplicationException) as exc_info:
        ProcessingRunPointsCleanupService(
            settings=settings,
            client=client,
        ).delete(request=cleanup_request())

    assert exc_info.value.code == "qdrant_cleanup_count_mismatch"
