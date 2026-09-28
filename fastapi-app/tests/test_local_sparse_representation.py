from dataclasses import dataclass

import pytest
from qdrant_client import models

from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.points import PointPayload, build_point
from app.infrastructure.qdrant.schema import SPARSE_VECTOR_NAME
from app.processing.chunks import NormalizedChunk
from app.processing.local_sparse import LocalBm25Representer


@dataclass
class FakeSparseEmbedding:
    indices: list[int]
    values: list[float]


class FakeBm25Embedder:
    def __init__(self, embeddings: list[FakeSparseEmbedding]) -> None:
        self._embeddings = embeddings
        self.received_texts: list[str] | None = None

    def embed(self, texts: list[str]):
        self.received_texts = texts
        return iter(self._embeddings)


def test_local_bm25_representation_preserves_count_and_order() -> None:
    chunks = [
        NormalizedChunk(text="المادة السابعة", page=1),
        NormalizedChunk(text="قانون العمل", page=2),
    ]
    embedder = FakeBm25Embedder(
        [
            FakeSparseEmbedding(indices=[10, 20], values=[0.5, 1.0]),
            FakeSparseEmbedding(indices=[30], values=[1.5]),
        ]
    )

    result = LocalBm25Representer(embedder=embedder).represent(chunks)

    assert embedder.received_texts == [
        "المادة السابعة",
        "قانون العمل",
    ]

    assert len(result) == 2
    assert all(isinstance(vector, models.SparseVector) for vector in result)

    assert result[0].indices == [10, 20]
    assert result[0].values == [0.5, 1.0]
    assert result[1].indices == [30]
    assert result[1].values == [1.5]


def test_local_bm25_representation_returns_empty_without_embedding() -> None:
    embedder = FakeBm25Embedder([])

    result = LocalBm25Representer(embedder=embedder).represent([])

    assert result == []
    assert embedder.received_texts is None


def test_local_bm25_representation_rejects_count_mismatch() -> None:
    chunks = [
        NormalizedChunk(text="الأول"),
        NormalizedChunk(text="الثاني"),
    ]
    embedder = FakeBm25Embedder(
        [
            FakeSparseEmbedding(indices=[1], values=[1.0]),
        ]
    )

    with pytest.raises(ApplicationException) as exc_info:
        LocalBm25Representer(embedder=embedder).represent(chunks)

    assert exc_info.value.code == "local_sparse_result_invalid"


def test_local_bm25_representation_rejects_malformed_sparse_vector() -> None:
    chunks = [NormalizedChunk(text="اختبار")]
    embedder = FakeBm25Embedder(
        [
            FakeSparseEmbedding(
                indices=[1, 2],
                values=[1.0],
            )
        ]
    )

    with pytest.raises(ApplicationException) as exc_info:
        LocalBm25Representer(embedder=embedder).represent(chunks)

    assert exc_info.value.code == "local_sparse_result_invalid"


def test_local_bm25_uses_qdrant_bm25_with_arabic_language(monkeypatch) -> None:
    captured: dict[str, object] = {}

    class FakeFastembed:
        class SparseTextEmbedding:
            def __init__(
                self,
                *,
                model_name: str,
                language: str,
                disable_stemmer: bool,
            ) -> None:
                captured["model_name"] = model_name
                captured["language"] = language
                captured["disable_stemmer"] = disable_stemmer

    monkeypatch.setattr(
        "app.processing.local_sparse.import_module",
        lambda module_name: FakeFastembed,
    )

    LocalBm25Representer()

    assert captured == {
        "model_name": "Qdrant/bm25",
        "language": "arabic",
        "disable_stemmer": True,
    }


def test_local_bm25_output_matches_qdrant_sparse_point_contract() -> None:
    chunks = [
        NormalizedChunk(
            text="قانون العمل السوري",
            page=1,
        )
    ]
    embedder = FakeBm25Embedder(
        [
            FakeSparseEmbedding(
                indices=[10, 20],
                values=[0.5, 1.0],
            )
        ]
    )

    sparse_vector = LocalBm25Representer(
        embedder=embedder
    ).represent(chunks)[0]

    payload = PointPayload(
        user_id=1,
        document_id=2,
        processing_run_id=3,
        processing_profile="hybrid_local",
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
        sparse_representation=sparse_vector,
    )

    assert isinstance(point.vector, dict)
    assert point.vector[SPARSE_VECTOR_NAME] == sparse_vector
