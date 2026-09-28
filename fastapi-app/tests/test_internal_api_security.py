import os

import pytest
from app.core.config import get_settings
from app.main import create_app
from fastapi.testclient import TestClient

TEST_API_KEY = "d4-test-internal-key"


def create_test_client() -> TestClient:
    os.environ["INTERNAL_API_KEY"] = TEST_API_KEY
    get_settings.cache_clear()

    return TestClient(create_app())


def test_missing_internal_api_key_is_rejected() -> None:
    client = create_test_client()

    response = client.get("/")

    assert response.status_code == 401


def test_invalid_internal_api_key_is_rejected() -> None:
    client = create_test_client()

    response = client.get(
        "/",
        headers={"X-Internal-API-Key": "wrong-key"},
    )

    assert response.status_code == 401


def test_valid_internal_api_key_is_allowed() -> None:
    client = create_test_client()

    response = client.get(
        "/",
        headers={"X-Internal-API-Key": TEST_API_KEY},
    )

    assert response.status_code == 404


# Middleware must reject before body parsing, service creation, or data access.


@pytest.mark.parametrize(
    "method,path",
    [
        ("GET", "/api/v1/health"),
        ("GET", "/api/v1/capabilities"),
        ("POST", "/api/v1/documents/process"),
        ("DELETE", "/api/v1/documents/processing-runs/points"),
        ("POST", "/api/v1/rag/query"),
        ("POST", "/api/v1/admin/chunks"),
        ("POST", "/api/v1/admin/evaluate-question"),
        ("GET", "/docs"),
        ("GET", "/openapi.json"),
    ],
)
@pytest.mark.parametrize("key", [None, "", "wrong-key"])
def test_all_internal_routes_reject_unauthenticated_requests(method, path, key):
    client = create_test_client()
    headers = {} if key is None else {"X-Internal-API-Key": key}
    response = client.request(method, path, headers=headers)
    assert response.status_code == 401
    assert response.json() == {"detail": "Unauthorized"}
