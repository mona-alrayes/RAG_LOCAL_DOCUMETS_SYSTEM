from uuid import UUID

from fastapi import FastAPI
from fastapi.testclient import TestClient
from pydantic import SecretStr
from qdrant_client import QdrantClient, models

from app.api.v1.admin_routes import get_admin_chunks_service, router
from app.core.config import Settings
from app.middleware.internal_api_auth import InternalApiAuthMiddleware
from app.services.admin_chunks import AdminChunksService


def test_chunks_are_paginated_scoped_and_never_contain_vectors():
    settings = Settings(_env_file=None)
    client = QdrantClient(":memory:")
    client.create_collection(
        settings.qdrant_cloud_collection,
        vectors_config=models.VectorParams(size=2, distance=models.Distance.COSINE),
    )
    base = dict(
        user_id=7,
        document_id=12,
        processing_run_id=81,
        processing_profile="cloud",
        text="A chunk",
        chunk_index=0,
        source="notes.txt",
        page=None,
        section=None,
    )
    payloads = [
        base,
        {**base, "chunk_index": 1},
        {**base, "user_id": 8},
        {**base, "document_id": 13},
        {**base, "processing_run_id": 82},
        {**base, "processing_profile": "hybrid_local"},
    ]
    client.upsert(
        settings.qdrant_cloud_collection,
        points=[
            models.PointStruct(
                id=str(UUID(int=100 - i)),
                vector=[1.0, 0.0],
                payload={**p, "secret": "never-display", "vectors": [99.0]},
            )
            for i, p in enumerate(payloads)
        ],
    )
    service = AdminChunksService(settings=settings, client=client)
    app = FastAPI()
    app.include_router(router)
    app.add_middleware(
        InternalApiAuthMiddleware, internal_api_key=SecretStr("test-key")
    )
    app.dependency_overrides[get_admin_chunks_service] = lambda: service
    http = TestClient(app)
    body = dict(
        user_id=7,
        document_id=12,
        processing_run_id=81,
        processing_profile="cloud",
        limit=1,
    )
    assert http.post("/api/v1/admin/chunks", json=body).status_code == 401
    response = http.post(
        "/api/v1/admin/chunks", json=body, headers={"X-Internal-API-Key": "test-key"}
    )
    assert response.status_code == 200
    data = response.json()
    assert data["total"] == 2
    assert len(data["chunks"]) == 1
    assert data["chunks"][0]["chunk_index"] == 0
    assert data["next_cursor"]
    assert "vectors" not in response.text and "secret" not in response.text
    second = http.post(
        "/api/v1/admin/chunks",
        json={**body, "cursor": data["next_cursor"]},
        headers={"X-Internal-API-Key": "test-key"},
    ).json()
    assert second["chunks"][0]["point_id"] != data["chunks"][0]["point_id"]
    assert second["chunks"][0]["chunk_index"] == 1
    assert second["next_cursor"] is None
    for extra in [{"limit": 101}, {"cursor": "../bad"}, {"collection": "arbitrary"}]:
        assert (
            http.post(
                "/api/v1/admin/chunks",
                json={**body, **extra},
                headers={"X-Internal-API-Key": "test-key"},
            ).status_code
            == 422
        )
    client.close()
