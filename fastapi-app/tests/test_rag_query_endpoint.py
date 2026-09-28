import json

from fastapi import FastAPI
from fastapi.testclient import TestClient

from app.api.rag_dependencies import (
    get_rag_query_service,
)
from app.api.v1.rag_routes import router
from app.schemas.rag_events import (
    RagCompletedEvent,
    RagTimings,
    RagTokenEvent,
)


class FakeRagQueryService:
    def prepare(self, request):
        assert request.user_id == 7
        assert request.question == "ما محتوى الوثيقة؟"

        assert len(request.document_targets) == 1

        target = request.document_targets[0]

        assert target.document_id == 10
        assert target.processing_run_id == 20
        assert target.processing_profile.value == "cloud"

        return object()

    async def stream(self, prepared):
        yield RagTokenEvent(
            content="الجواب",
        )

        yield RagCompletedEvent(
            answer="الجواب",
            sources=[],
            timings_ms=RagTimings(
                query_embedding=None,
                retrieval=1.0,
                fusion=1.0,
                reranking=None,
                context_building=1.0,
                generation=1.0,
                total=4.0,
            ),
        )


def test_rag_query_endpoint_streams_ndjson() -> None:
    app = FastAPI()
    app.include_router(router)

    app.dependency_overrides[
        get_rag_query_service
    ] = lambda: FakeRagQueryService()

    client = TestClient(app)

    response = client.post(
        "/api/v1/rag/query",
        json={
            "user_id": 7,
            "question": "ما محتوى الوثيقة؟",
            "document_targets": [
                {
                    "document_id": 10,
                    "processing_run_id": 20,
                    "processing_profile": "cloud",
                }
            ],
            "recent_completed_turns": [],
        },
    )

    assert response.status_code == 200

    assert (
        response.headers["content-type"]
        .startswith("application/x-ndjson")
    )

    events = [
        json.loads(line)
        for line in response.text.splitlines()
        if line.strip()
    ]

    assert events[0] == {
        "type": "token",
        "content": "الجواب",
    }

    assert events[1]["type"] == "completed"
    assert events[1]["answer"] == "الجواب"


def test_rag_query_rejects_untrusted_transport_fields() -> None:
    app = FastAPI()
    app.include_router(router)

    app.dependency_overrides[
        get_rag_query_service
    ] = lambda: FakeRagQueryService()

    client = TestClient(app)

    response = client.post(
        "/api/v1/rag/query",
        json={
            "user_id": 7,
            "question": "test",
            "provider": "ollama",
            "document_targets": [],
            "recent_completed_turns": [],
        },
    )

    assert response.status_code == 422
