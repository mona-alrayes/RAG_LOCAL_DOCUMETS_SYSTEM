from qdrant_client import models

from app.infrastructure.qdrant.points import PointPayload, build_point
from app.infrastructure.qdrant.schema import SPARSE_VECTOR_NAME
from app.processing.cloud_chunking import NormalizedChunk
from app.processing.cloud_sparse import CloudSparseRepresenter


def test_cloud_sparse_representation_uses_qdrant_multilingual_bm25() -> None:
    chunks = [
        NormalizedChunk(text="النص الأول"),
        NormalizedChunk(text="النص الثاني"),
    ]

    documents = CloudSparseRepresenter().represent(chunks)

    assert [document.text for document in documents] == [
        "النص الأول",
        "النص الثاني",
    ]
    assert all(document.model == "Qdrant/bm25" for document in documents)
    assert all(
        document.options == {"tokenizer": "multilingual"}
        for document in documents
    )


def test_cloud_sparse_output_matches_qdrant_point_contract() -> None:
    chunks = [
        NormalizedChunk(
            text="قانون العمل السوري",
            page=1,
        )
    ]

    sparse_representation = CloudSparseRepresenter().represent(chunks)[0]

    payload = PointPayload(
        user_id=1,
        document_id=2,
        processing_run_id=3,
        processing_profile="cloud",
        file_type="pdf",
        source="document.pdf",
        page=1,
        section=None,
        chunk_index=0,
        text=chunks[0].text,
    )

    point = build_point(
        payload=payload,
        dense_vector=[0.1, 0.2],
        sparse_representation=sparse_representation,
    )

    assert isinstance(point.vector, dict)

    stored_sparse_representation = point.vector[SPARSE_VECTOR_NAME]

    assert isinstance(stored_sparse_representation, models.Document)
    assert stored_sparse_representation.text == "قانون العمل السوري"
    assert stored_sparse_representation.model == "Qdrant/bm25"
    assert stored_sparse_representation.options == {
        "tokenizer": "multilingual",
    }
