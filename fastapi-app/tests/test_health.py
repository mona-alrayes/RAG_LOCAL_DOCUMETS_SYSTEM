import os

from fastapi.testclient import TestClient

from app.core.config import get_settings
from app.main import create_app


TEST_API_KEY = "d5-test-internal-key"


def test_health_endpoint_returns_ok_with_valid_internal_api_key() -> None:
    os.environ["INTERNAL_API_KEY"] = TEST_API_KEY
    get_settings.cache_clear()

    client = TestClient(create_app())

    response = client.get(
        "/api/v1/health",
        headers={"X-Internal-API-Key": TEST_API_KEY},
    )

    assert response.status_code == 200
    assert response.json()["status"] == "ok"
