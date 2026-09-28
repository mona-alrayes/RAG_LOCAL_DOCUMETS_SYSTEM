import pytest
from pydantic import ValidationError

from app.processing.base import ProcessingProfile
from app.schemas.documents import (
    DocumentFileType,
    ProcessDocumentRequest,
    ProcessDocumentResponse,
)
from app.schemas.rag import RagQueryRequest


def test_process_document_request_contract() -> None:
    request = ProcessDocumentRequest(
        user_id=7,
        document_id=152,
        processing_run_id=901,
        processing_profile="cloud",
        file_type="pdf",
    )

    assert request.user_id == 7
    assert request.document_id == 152
    assert request.processing_run_id == 901
    assert (
        request.processing_profile
        is ProcessingProfile.CLOUD
    )
    assert request.file_type is DocumentFileType.PDF


def test_process_document_request_rejects_untrusted_contract_values() -> None:
    with pytest.raises(ValidationError):
        ProcessDocumentRequest(
            user_id=7,
            document_id=152,
            processing_run_id=901,
            processing_profile="both",
            file_type="exe",
        )


def test_process_document_response_contract() -> None:
    response = ProcessDocumentResponse(
        document_id=152,
        processing_run_id=901,
        profile="cloud",
        status="indexed",
        qdrant_collection="rag_documents_cloud",
        profile_snapshot={
            "profile": "cloud",
            "chunking": {
                "chunk_size": 800,
                "chunk_overlap": 120,
            },
            "dense_embedding": {
                "provider": "jina",
                "model": "jina-embeddings-v3",
                "vector_dimension": 1024,
            },
            "sparse_representation": {
                "provider": "qdrant",
                "model": "bm25",
                "tokenizer": "multilingual",
            },
            "batching": {
                "batch_size": 32,
                "wait_between_batches_seconds": 0,
                "rate_limit_retry_wait_seconds": 1,
                "max_retries": 3,
            },
        },
        total_pages=None,
        total_chunks=184,
        vector_count=184,
        vector_dimension=1024,
        stage_timings_ms={
            "parse": 40,
            "chunk": 20,
            "dense_embedding": 120,
            "sparse_representation": 35,
            "total": 215,
        },
        warnings=[],
    )

    assert response.document_id == 152
    assert response.processing_run_id == 901
    assert response.profile is ProcessingProfile.CLOUD
    assert response.status == "indexed"
    assert (
        response.qdrant_collection
        == "rag_documents_cloud"
    )
    assert response.total_chunks == 184
    assert response.total_pages is None
    assert response.vector_count == 184
    assert response.vector_dimension == 1024


def test_rag_query_request_contract() -> None:
    request = RagQueryRequest(
        user_id=7,
        document_targets=[
            {
                "document_id": 12,
                "processing_run_id": 81,
                "processing_profile": "cloud",
            }
        ],
        question="ما أهم النتائج؟",
        recent_completed_turns=[
            {
                "user": "لخص المنهجية.",
                "assistant": "تعتمد المنهجية على ...",
            }
        ],
    )

    target = request.document_targets[0]

    assert target.document_id == 12
    assert target.processing_run_id == 81
    assert (
        target.processing_profile
        is ProcessingProfile.CLOUD
    )

    assert len(request.recent_completed_turns) == 1

    assert (
        request.recent_completed_turns[0].user
        == "لخص المنهجية."
    )


def test_rag_query_request_rejects_untrusted_qdrant_collection() -> None:
    with pytest.raises(ValidationError):
        RagQueryRequest(
            user_id=7,
            question="ما أهم النتائج؟",
            document_targets=[
                {
                    "document_id": 12,
                    "processing_run_id": 81,
                    "processing_profile": "cloud",
                    "qdrant_collection": (
                        "forged_collection"
                    ),
                }
            ],
            recent_completed_turns=[],
        )


def test_rag_query_request_rejects_provider_injection() -> None:
    with pytest.raises(ValidationError):
        RagQueryRequest(
            user_id=7,
            question="ما أهم النتائج؟",
            provider="ollama",
            document_targets=[],
            recent_completed_turns=[],
        )
