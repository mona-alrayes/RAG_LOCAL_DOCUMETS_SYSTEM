import os
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app.api.dependencies import get_process_document_service
from app.core.config import Settings, get_settings
from app.main import create_app
from app.processing.base import ProcessingProfile
from app.processing.reporting import (
    ProcessingStage,
    build_profile_snapshot,
)
from app.schemas.documents import (
    ProcessDocumentRequest,
    ProcessDocumentResponse,
)


TEST_API_KEY = "h3-test-internal-key"


class FakeProcessDocumentService:
    def __init__(self) -> None:
        self.called = False
        self.request: ProcessDocumentRequest | None = None
        self.file_path: Path | None = None
        self.source: str | None = None
        self.file_content: bytes | None = None

    def process(
        self,
        *,
        request: ProcessDocumentRequest,
        file_path: Path,
        source: str,
    ) -> ProcessDocumentResponse:
        self.called = True
        self.request = request
        self.file_path = file_path
        self.source = source

        assert file_path.exists()

        self.file_content = file_path.read_bytes()

        settings = Settings(_env_file=None)

        return ProcessDocumentResponse(
            document_id=request.document_id,
            processing_run_id=request.processing_run_id,
            profile=request.processing_profile,
            status="indexed",
            qdrant_collection=settings.qdrant_cloud_collection,
            profile_snapshot=build_profile_snapshot(
                profile=request.processing_profile,
                settings=settings,
            ),
            total_pages=1,
            total_chunks=1,
            vector_count=1,
            vector_dimension=1024,
            stage_timings_ms={
                ProcessingStage.PARSE: 1,
                ProcessingStage.CHUNK: 1,
                ProcessingStage.DENSE_EMBEDDING: 1,
                ProcessingStage.SPARSE_REPRESENTATION: 1,
                ProcessingStage.TOTAL: 5,
            },
            warnings=[],
        )


def create_test_client(
    service: FakeProcessDocumentService,
) -> TestClient:
    os.environ["INTERNAL_API_KEY"] = TEST_API_KEY
    get_settings.cache_clear()

    app = create_app()

    app.dependency_overrides[
        get_process_document_service
    ] = lambda: service

    return TestClient(app)


def valid_form_data() -> dict[str, str]:
    return {
        "user_id": "10",
        "document_id": "20",
        "processing_run_id": "30",
        "processing_profile": "cloud",
        "file_type": "pdf",
    }


def test_process_document_accepts_multipart_contract_and_cleans_temp_file() -> None:
    service = FakeProcessDocumentService()
    client = create_test_client(service)

    response = client.post(
        "/api/v1/documents/process",
        headers={
            "X-Internal-API-Key": TEST_API_KEY,
        },
        data=valid_form_data(),
        files={
            "file": (
                "document.pdf",
                b"%PDF-test-content",
                "application/pdf",
            )
        },
    )

    assert response.status_code == 200

    body = response.json()

    assert body["document_id"] == 20
    assert body["processing_run_id"] == 30
    assert body["profile"] == "cloud"
    assert body["status"] == "indexed"

    assert service.called is True
    assert service.request is not None
    assert service.request.user_id == 10
    assert service.request.document_id == 20
    assert service.request.processing_run_id == 30
    assert (
        service.request.processing_profile
        is ProcessingProfile.CLOUD
    )

    assert service.source == "document.pdf"
    assert service.file_content == b"%PDF-test-content"

    assert service.file_path is not None
    assert service.file_path.suffix == ".pdf"
    assert service.file_path.exists() is False


def test_process_document_sanitizes_source_filename() -> None:
    service = FakeProcessDocumentService()
    client = create_test_client(service)

    response = client.post(
        "/api/v1/documents/process",
        headers={
            "X-Internal-API-Key": TEST_API_KEY,
        },
        data=valid_form_data(),
        files={
            "file": (
                "../../private/document.pdf",
                b"%PDF-test-content",
                "application/pdf",
            )
        },
    )

    assert response.status_code == 200
    assert service.source == "document.pdf"

    response_text = response.text

    assert "../../private" not in response_text
    assert str(service.file_path) not in response_text


@pytest.mark.parametrize(
    ("field", "invalid_value"),
    [
        ("processing_profile", "both"),
        ("file_type", "exe"),
    ],
)
def test_process_document_rejects_invalid_metadata(
    field: str,
    invalid_value: str,
) -> None:
    service = FakeProcessDocumentService()
    client = create_test_client(service)

    data = valid_form_data()
    data[field] = invalid_value

    response = client.post(
        "/api/v1/documents/process",
        headers={
            "X-Internal-API-Key": TEST_API_KEY,
        },
        data=data,
        files={
            "file": (
                "document.pdf",
                b"%PDF-test-content",
                "application/pdf",
            )
        },
    )

    assert response.status_code == 422
    assert service.called is False


def test_process_document_response_does_not_expose_vectors_or_paths() -> None:
    service = FakeProcessDocumentService()
    client = create_test_client(service)

    response = client.post(
        "/api/v1/documents/process",
        headers={
            "X-Internal-API-Key": TEST_API_KEY,
        },
        data=valid_form_data(),
        files={
            "file": (
                "document.pdf",
                b"%PDF-test-content",
                "application/pdf",
            )
        },
    )

    assert response.status_code == 200

    body = response.json()

    assert "dense_vectors" not in body
    assert "sparse_vectors" not in body
    assert "file_path" not in body
    assert "temporary_path" not in body
