from unittest.mock import MagicMock

import pytest
from qdrant_client import QdrantClient, models

from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.indexer import (
    IndexingContext,
    QdrantDocumentIndexer,
)
from app.processing.chunks import NormalizedChunk


def test_document_indexer_persists_and_verifies_processing_run_points() -> None:
    client = MagicMock(spec=QdrantClient)
    client.count.return_value = models.CountResult(count=2)

    context = IndexingContext(
        user_id=7,
        document_id=12,
        processing_run_id=81,
        processing_profile="hybrid_local",
        file_type="pdf",
        source="document.pdf",
        collection_name="rag_documents_hybrid_local",
    )

    chunks = [
        NormalizedChunk(
            text="النص الأول",
            page=1,
            section="المقدمة",
        ),
        NormalizedChunk(
            text="النص الثاني",
            page=2,
            section=None,
        ),
    ]

    dense_vectors = [
        [0.1, 0.2],
        [0.3, 0.4],
    ]

    sparse_representations = [
        models.SparseVector(
            indices=[10],
            values=[0.5],
        ),
        models.SparseVector(
            indices=[20],
            values=[0.8],
        ),
    ]

    result = QdrantDocumentIndexer(client).index(
        context=context,
        chunks=chunks,
        dense_vectors=dense_vectors,
        sparse_representations=sparse_representations,
    )

    assert result.collection_name == "rag_documents_hybrid_local"
    assert result.vector_count == 2

    client.upsert.assert_called_once()

    upsert_call = client.upsert.call_args

    assert (
        upsert_call.kwargs["collection_name"]
        == "rag_documents_hybrid_local"
    )
    assert upsert_call.kwargs["wait"] is True

    points = upsert_call.kwargs["points"]

    assert len(points) == 2

    assert points[0].payload == {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
        "processing_profile": "hybrid_local",
        "file_type": "pdf",
        "source": "document.pdf",
        "page": 1,
        "section": "المقدمة",
        "chunk_index": 0,
        "text": "النص الأول",
    }

    assert points[1].payload["chunk_index"] == 1
    assert points[1].payload["text"] == "النص الثاني"

    client.count.assert_called_once()

    count_call = client.count.call_args

    assert (
        count_call.kwargs["collection_name"]
        == "rag_documents_hybrid_local"
    )
    assert count_call.kwargs["exact"] is True

    count_filter = count_call.kwargs["count_filter"]

    assert {
        condition.key: condition.match.value
        for condition in count_filter.must
    } == {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
    }


def test_document_indexer_retry_reuses_same_deterministic_point_ids() -> None:
    client = MagicMock(spec=QdrantClient)
    client.count.return_value = models.CountResult(count=2)

    context = IndexingContext(
        user_id=7,
        document_id=12,
        processing_run_id=81,
        processing_profile="hybrid_local",
        file_type="pdf",
        source="document.pdf",
        collection_name="rag_documents_hybrid_local",
    )

    chunks = [
        NormalizedChunk(
            text="النص الأول",
            page=1,
            section="المقدمة",
        ),
        NormalizedChunk(
            text="النص الثاني",
            page=2,
            section=None,
        ),
    ]

    dense_vectors = [
        [0.1, 0.2],
        [0.3, 0.4],
    ]

    sparse_representations = [
        models.SparseVector(
            indices=[10],
            values=[0.5],
        ),
        models.SparseVector(
            indices=[20],
            values=[0.8],
        ),
    ]

    indexer = QdrantDocumentIndexer(client)

    first_result = indexer.index(
        context=context,
        chunks=chunks,
        dense_vectors=dense_vectors,
        sparse_representations=sparse_representations,
    )

    second_result = indexer.index(
        context=context,
        chunks=chunks,
        dense_vectors=dense_vectors,
        sparse_representations=sparse_representations,
    )

    assert first_result.vector_count == 2
    assert second_result.vector_count == 2

    assert client.upsert.call_count == 2
    assert client.count.call_count == 2

    first_points = client.upsert.call_args_list[0].kwargs["points"]
    second_points = client.upsert.call_args_list[1].kwargs["points"]

    first_ids = [point.id for point in first_points]
    second_ids = [point.id for point in second_points]

    assert len(first_ids) == 2
    assert len(set(first_ids)) == 2

    # إعادة نفس ProcessingRun تنتج نفس IDs بالضبط،
    # وبالتالي Qdrant upsert يستبدل النقاط ولا ينشئ duplicates.
    assert second_ids == first_ids

    assert all(
        call.kwargs["wait"] is True
        for call in client.upsert.call_args_list
    )


@pytest.mark.parametrize(
    (
        "dense_vectors",
        "sparse_representations",
        "expected_code",
    ),
    [
        (
            [[0.1, 0.2]],
            [
                models.SparseVector(indices=[10], values=[0.5]),
                models.SparseVector(indices=[20], values=[0.8]),
            ],
            "qdrant_index_dense_count_mismatch",
        ),
        (
            [
                [0.1, 0.2],
                [0.3, 0.4],
            ],
            [
                models.SparseVector(indices=[10], values=[0.5]),
            ],
            "qdrant_index_sparse_count_mismatch",
        ),
    ],
)
def test_document_indexer_rejects_representation_count_mismatch_before_upsert(
    dense_vectors: list[list[float]],
    sparse_representations: list[models.SparseVector],
    expected_code: str,
) -> None:
    client = MagicMock(spec=QdrantClient)

    context = IndexingContext(
        user_id=7,
        document_id=12,
        processing_run_id=81,
        processing_profile="hybrid_local",
        file_type="pdf",
        source="document.pdf",
        collection_name="rag_documents_hybrid_local",
    )

    chunks = [
        NormalizedChunk(text="النص الأول"),
        NormalizedChunk(text="النص الثاني"),
    ]

    with pytest.raises(ApplicationException) as exc_info:
        QdrantDocumentIndexer(client).index(
            context=context,
            chunks=chunks,
            dense_vectors=dense_vectors,
            sparse_representations=sparse_representations,
        )

    assert exc_info.value.code == expected_code

    client.upsert.assert_not_called()
    client.count.assert_not_called()


def test_document_indexer_rejects_persisted_count_mismatch() -> None:
    client = MagicMock(spec=QdrantClient)
    client.count.return_value = models.CountResult(count=1)

    context = IndexingContext(
        user_id=7,
        document_id=12,
        processing_run_id=81,
        processing_profile="hybrid_local",
        file_type="pdf",
        source="document.pdf",
        collection_name="rag_documents_hybrid_local",
    )

    chunks = [
        NormalizedChunk(text="النص الأول"),
        NormalizedChunk(text="النص الثاني"),
    ]

    dense_vectors = [
        [0.1, 0.2],
        [0.3, 0.4],
    ]

    sparse_representations = [
        models.SparseVector(
            indices=[10],
            values=[0.5],
        ),
        models.SparseVector(
            indices=[20],
            values=[0.8],
        ),
    ]

    with pytest.raises(ApplicationException) as exc_info:
        QdrantDocumentIndexer(client).index(
            context=context,
            chunks=chunks,
            dense_vectors=dense_vectors,
            sparse_representations=sparse_representations,
        )

    assert exc_info.value.code == "qdrant_index_count_mismatch"

    client.upsert.assert_called_once()
    client.count.assert_called_once()
