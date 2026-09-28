import json

import pytest
from pydantic import ValidationError

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.parsing.normalized import NormalizedDocument
from app.processing.base import ProcessingProfile
from app.processing.chunks import NormalizedChunk
from app.processing.reporting import (
    ProcessingReportBuilder,
    ProcessingStage,
    ProcessingWarning,
    build_profile_snapshot,
)


def make_cloud_snapshot():
    settings = Settings(
        _env_file=None,
        chunk_size=800,
        chunk_overlap=80,
        cloud_embed_model="jina-embeddings-v3",
        embed_batch_size=6,
        wait_between_batches=3,
        rate_limit_retry_wait=30,
        max_retries=5,
        jinaai_api_key="super-secret-key",
    )

    return build_profile_snapshot(
        profile=ProcessingProfile.CLOUD,
        settings=settings,
    )


def test_cloud_profile_snapshot_contains_only_safe_processing_configuration():
    snapshot = make_cloud_snapshot()

    payload = snapshot.model_dump(mode="json")
    serialized = json.dumps(payload)

    assert payload["profile"] == "cloud"
    assert payload["chunking"] == {
        "chunk_size": 800,
        "chunk_overlap": 80,
        # يميز التقطيع الجديد عن تشغيلات SentenceSplitter السابقة.
        "version": "markdown-sections-scoped-overlap-v3",
    }
    assert payload["dense_embedding"] == {
        "provider": "jina",
        "model": "jina-embeddings-v3",
        "vector_dimension": 1024,
    }
    assert payload["sparse_representation"] == {
        "provider": "qdrant",
        "model": "Qdrant/bm25",
        "tokenizer": "multilingual",
        "language": None,
        "disable_stemmer": None,
    }
    assert payload["batching"] == {
        "batch_size": 6,
        "wait_between_batches_seconds": 3.0,
        "rate_limit_retry_wait_seconds": 30.0,
        "max_retries": 5,
    }

    assert "super-secret-key" not in serialized
    assert "api_key" not in serialized
    assert "cost" not in serialized.lower()


def test_hybrid_local_snapshot_contains_local_processing_configuration():
    settings = Settings(
        _env_file=None,
        local_embed_model="BAAI/bge-m3",
        chunk_size=700,
        chunk_overlap=70,
    )

    snapshot = build_profile_snapshot(
        profile=ProcessingProfile.HYBRID_LOCAL,
        settings=settings,
    )

    payload = snapshot.model_dump(mode="json")

    assert payload["profile"] == "hybrid_local"
    assert payload["chunking"] == {
        "chunk_size": 700,
        "chunk_overlap": 70,
        "version": "markdown-sections-scoped-overlap-v3",
    }
    assert payload["dense_embedding"] == {
        "provider": "transformers",
        "model": "BAAI/bge-m3",
        "vector_dimension": 1024,
    }
    assert payload["sparse_representation"] == {
        "provider": "fastembed",
        "model": "Qdrant/bm25",
        "tokenizer": None,
        "language": "arabic",
        "disable_stemmer": True,
    }
    assert payload["batching"] is None


def test_report_builder_collects_counts_dimension_pages_timings_and_warnings():
    documents = [
        NormalizedDocument(text="Page one", page=1),
        NormalizedDocument(text="Page two", page=2),
        NormalizedDocument(text="More page two", page=2),
    ]
    chunks = [
        NormalizedChunk(text="Chunk one", page=1),
        NormalizedChunk(text="Chunk two", page=2),
    ]
    vectors = [
        [0.0] * 1024,
        [1.0] * 1024,
    ]
    warning = ProcessingWarning(
        code="page_layout_simplified",
        message="Page layout was simplified during parsing.",
        stage=ProcessingStage.PARSE,
    )

    report = ProcessingReportBuilder().build(
        profile_snapshot=make_cloud_snapshot(),
        documents=documents,
        chunks=chunks,
        dense_vectors=vectors,
        stage_timings_ms={
            ProcessingStage.PARSE: 900,
            ProcessingStage.CHUNK: 120,
            ProcessingStage.DENSE_EMBEDDING: 2400,
            ProcessingStage.SPARSE_REPRESENTATION: 300,
            ProcessingStage.TOTAL: 3720,
        },
        warnings=[warning],
    )

    assert report.total_pages == 2
    assert report.total_chunks == 2
    assert report.vector_count == 2
    assert report.vector_dimension == 1024
    assert report.stage_timings_ms[ProcessingStage.PARSE] == 900
    assert report.stage_timings_ms[ProcessingStage.TOTAL] == 3720
    assert report.warnings == [warning]


def test_report_builder_does_not_claim_page_count_when_page_metadata_is_incomplete():
    documents = [
        NormalizedDocument(text="Known page", page=1),
        NormalizedDocument(text="Unknown page", page=None),
    ]

    report = ProcessingReportBuilder().build(
        profile_snapshot=make_cloud_snapshot(),
        documents=documents,
        chunks=[],
        dense_vectors=[],
        stage_timings_ms={},
    )

    assert report.total_pages is None


def test_report_builder_supports_empty_processing_results():
    report = ProcessingReportBuilder().build(
        profile_snapshot=make_cloud_snapshot(),
        documents=[],
        chunks=[],
        dense_vectors=[],
        stage_timings_ms={},
    )

    assert report.total_pages is None
    assert report.total_chunks == 0
    assert report.vector_count == 0
    assert report.vector_dimension is None
    assert report.stage_timings_ms == {}
    assert report.warnings == []


def test_report_builder_rejects_vector_count_mismatch():
    chunks = [
        NormalizedChunk(text="Chunk one"),
        NormalizedChunk(text="Chunk two"),
    ]

    with pytest.raises(ApplicationException) as exc_info:
        ProcessingReportBuilder().build(
            profile_snapshot=make_cloud_snapshot(),
            documents=[],
            chunks=chunks,
            dense_vectors=[[0.0] * 1024],
            stage_timings_ms={},
        )

    assert exc_info.value.code == "processing_report_vector_count_mismatch"


def test_report_builder_rejects_inconsistent_vector_dimensions():
    chunks = [
        NormalizedChunk(text="Chunk one"),
        NormalizedChunk(text="Chunk two"),
    ]

    with pytest.raises(ApplicationException) as exc_info:
        ProcessingReportBuilder().build(
            profile_snapshot=make_cloud_snapshot(),
            documents=[],
            chunks=chunks,
            dense_vectors=[
                [0.0] * 1024,
                [0.0] * 1023,
            ],
            stage_timings_ms={},
        )

    assert (
        exc_info.value.code
        == "processing_report_inconsistent_vector_dimensions"
    )


def test_report_builder_rejects_invalid_stage_timings():
    with pytest.raises(ApplicationException) as exc_info:
        ProcessingReportBuilder().build(
            profile_snapshot=make_cloud_snapshot(),
            documents=[],
            chunks=[],
            dense_vectors=[],
            stage_timings_ms={
                ProcessingStage.PARSE: -1,
            },
        )

    assert exc_info.value.code == "processing_report_invalid_timing"


def test_warning_rejects_multiline_message():
    with pytest.raises(ValidationError):
        ProcessingWarning(
            code="unsafe_warning",
            message="First line\nTraceback: sensitive details",
            stage=ProcessingStage.PARSE,
        )


def test_serialized_report_contains_no_raw_vectors_or_cost_fields():
    report = ProcessingReportBuilder().build(
        profile_snapshot=make_cloud_snapshot(),
        documents=[NormalizedDocument(text="Document", page=1)],
        chunks=[NormalizedChunk(text="Chunk", page=1)],
        dense_vectors=[[0.123456789] * 1024],
        stage_timings_ms={
            ProcessingStage.PARSE: 10,
            ProcessingStage.CHUNK: 20,
            ProcessingStage.DENSE_EMBEDDING: 30,
            ProcessingStage.SPARSE_REPRESENTATION: 40,
            ProcessingStage.TOTAL: 100,
        },
    )

    payload = report.model_dump(mode="json")
    serialized = json.dumps(payload)

    assert "dense_vectors" not in payload
    assert "vectors" not in payload
    assert "comparison_report" not in payload
    assert "cost" not in serialized.lower()
    assert "0.123456789" not in serialized
