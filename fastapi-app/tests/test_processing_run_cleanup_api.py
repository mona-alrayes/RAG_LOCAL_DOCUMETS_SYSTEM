import os

from fastapi.testclient import TestClient

from app.api.dependencies import (
    get_processing_run_points_cleanup_service,
)
from app.core.config import get_settings
from app.main import create_app
from app.schemas.documents import (
    DeleteProcessingRunPointsRequest,
    DeleteProcessingRunPointsResponse,
)


TEST_API_KEY = "h7-test-internal-key"


class FakeProcessingRunPointsCleanupService:
    def __init__(self) -> None:
        self.called = False
        self.request: DeleteProcessingRunPointsRequest | None = None

    def delete(
        self,
        *,
        request: DeleteProcessingRunPointsRequest,
    ) -> DeleteProcessingRunPointsResponse:
        self.called = True
        self.request = request

        return DeleteProcessingRunPointsResponse(
            document_id=request.document_id,
            processing_run_id=request.processing_run_id,
            status="deleted",
        )


def create_test_client(
    service: FakeProcessingRunPointsCleanupService,
) -> TestClient:
    os.environ["INTERNAL_API_KEY"] = TEST_API_KEY
    get_settings.cache_clear()

    app = create_app()

    app.dependency_overrides[
        get_processing_run_points_cleanup_service
    ] = lambda: service

    return TestClient(app)


def valid_payload() -> dict[str, int | str]:
    return {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
        "processing_profile": "cloud",
    }


def test_cleanup_endpoint_deletes_the_requested_processing_run_scope() -> None:
    service = FakeProcessingRunPointsCleanupService()
    client = create_test_client(service)

    response = client.request(
        "DELETE",
        "/api/v1/documents/processing-runs/points",
        headers={
            "X-Internal-API-Key": TEST_API_KEY,
        },
        json=valid_payload(),
    )

    assert response.status_code == 200
    assert response.json() == {
        "document_id": 12,
        "processing_run_id": 81,
        "status": "deleted",
    }

    assert service.called is True
    assert service.request is not None
    assert service.request.user_id == 7
    assert service.request.document_id == 12
    assert service.request.processing_run_id == 81
    assert service.request.processing_profile.value == "cloud"


def test_cleanup_endpoint_rejects_an_invalid_profile() -> None:
    service = FakeProcessingRunPointsCleanupService()
    client = create_test_client(service)

    payload = valid_payload()
    payload["processing_profile"] = "both"

    response = client.request(
        "DELETE",
        "/api/v1/documents/processing-runs/points",
        headers={
            "X-Internal-API-Key": TEST_API_KEY,
        },
        json=payload,
    )

    assert response.status_code == 422
    assert service.called is False
