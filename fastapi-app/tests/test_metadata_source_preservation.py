from types import SimpleNamespace
from typing import Any

import pytest

from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.retrieval import (
    QdrantCloudDenseRetriever,
    QdrantHybridLocalDenseRetriever,
)
from app.processing.base import ProcessingProfile
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
    CloudRetrievalTarget,
)
from app.services.cross_profile_rank_fusion import (
    CrossProfileRankFusionService,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
    HybridLocalRetrievalTarget,
)


class FakeClient:
    def __init__(self, point: Any) -> None:
        self.point = point

    def query_points(
        self,
        **kwargs: Any,
    ) -> Any:
        return SimpleNamespace(
            points=[self.point]
        )


def payload(
    profile: ProcessingProfile,
    *,
    user_id: int = 7,
    document_id: int = 12,
    processing_run_id: int = 81,
) -> dict[str, Any]:
    return {
        "user_id": user_id,
        "document_id": document_id,
        "processing_run_id": processing_run_id,
        "processing_profile": profile.value,
        "chunk_index": 4,
        "text": "trusted chunk text",
        "page": 5,
        "section": "trusted section",
        "source": "trusted-source.pdf",
    }


def retrieve(
    *,
    profile: ProcessingProfile,
    point_payload: dict[str, Any],
    point_id: Any = "point-4",
    score: Any = 0.73,
    user_id: int = 7,
    document_id: int = 12,
    processing_run_id: int = 81,
) -> (
    CloudRetrievalResult
    | HybridLocalRetrievalResult
):
    point = SimpleNamespace(
        id=point_id,
        score=score,
        payload=point_payload,
    )

    if profile is ProcessingProfile.CLOUD:
        retriever = QdrantCloudDenseRetriever(
            client=FakeClient(point)
        )
        target = CloudRetrievalTarget(
            document_id=document_id,
            processing_run_id=processing_run_id,
            processing_profile=profile,
        )
    else:
        retriever = (
            QdrantHybridLocalDenseRetriever(
                client=FakeClient(point)
            )
        )
        target = HybridLocalRetrievalTarget(
            document_id=document_id,
            processing_run_id=processing_run_id,
            processing_profile=profile,
        )

    results = retriever.retrieve(
        collection_name="test-collection",
        user_id=user_id,
        target=target,
        query_vector=[0.1],
        limit=5,
    )

    assert len(results) == 1

    return results[0]


def invalid_result_code(
    profile: ProcessingProfile,
) -> str:
    if profile is ProcessingProfile.CLOUD:
        return "cloud_retrieval_result_invalid"

    return (
        "hybrid_local_retrieval_result_invalid"
    )


def invalid_scope_code(
    profile: ProcessingProfile,
) -> str:
    if profile is ProcessingProfile.CLOUD:
        return (
            "cloud_retrieval_result_scope_invalid"
        )

    return (
        "hybrid_local_retrieval_result_"
        "scope_invalid"
    )


@pytest.mark.parametrize(
    "profile",
    [
        ProcessingProfile.CLOUD,
        ProcessingProfile.HYBRID_LOCAL,
    ],
)
def test_retrieval_preserves_complete_source_metadata(
    profile: ProcessingProfile,
) -> None:
    result = retrieve(
        profile=profile,
        point_payload=payload(profile),
    )

    assert result.point_id == "point-4"
    assert result.retrieval_score == 0.73
    assert result.document_id == 12
    assert result.processing_run_id == 81
    assert result.processing_profile is profile
    assert result.chunk_index == 4
    assert result.text == "trusted chunk text"
    assert result.page == 5
    assert result.section == "trusted section"
    assert result.source == "trusted-source.pdf"


@pytest.mark.parametrize(
    ("field", "invalid_value"),
    [
        ("chunk_index", True),
        ("chunk_index", -1),
        ("text", None),
        ("text", "   "),
        ("page", "5"),
        ("page", 0),
        ("section", 123),
        ("source", None),
        ("source", "   "),
    ],
)
@pytest.mark.parametrize(
    "profile",
    [
        ProcessingProfile.CLOUD,
        ProcessingProfile.HYBRID_LOCAL,
    ],
)
def test_malformed_source_metadata_fails_closed(
    profile: ProcessingProfile,
    field: str,
    invalid_value: Any,
) -> None:
    point_payload = payload(profile)
    point_payload[field] = invalid_value

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        retrieve(
            profile=profile,
            point_payload=point_payload,
        )

    assert (
        exc_info.value.code
        == invalid_result_code(profile)
    )


@pytest.mark.parametrize(
    "field",
    [
        "chunk_index",
        "text",
        "page",
        "section",
        "source",
    ],
)
@pytest.mark.parametrize(
    "profile",
    [
        ProcessingProfile.CLOUD,
        ProcessingProfile.HYBRID_LOCAL,
    ],
)
def test_missing_source_metadata_fails_closed(
    profile: ProcessingProfile,
    field: str,
) -> None:
    point_payload = payload(profile)
    point_payload.pop(field)

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        retrieve(
            profile=profile,
            point_payload=point_payload,
        )

    assert (
        exc_info.value.code
        == invalid_result_code(profile)
    )


@pytest.mark.parametrize(
    "field",
    [
        "user_id",
        "document_id",
        "processing_run_id",
    ],
)
@pytest.mark.parametrize(
    "profile",
    [
        ProcessingProfile.CLOUD,
        ProcessingProfile.HYBRID_LOCAL,
    ],
)
def test_boolean_scope_ids_cannot_alias_integer_ids(
    profile: ProcessingProfile,
    field: str,
) -> None:
    point_payload = payload(
        profile,
        user_id=1,
        document_id=1,
        processing_run_id=1,
    )
    point_payload[field] = True

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        retrieve(
            profile=profile,
            point_payload=point_payload,
            user_id=1,
            document_id=1,
            processing_run_id=1,
        )

    assert (
        exc_info.value.code
        == invalid_scope_code(profile)
    )


@pytest.mark.parametrize(
    ("point_id", "score"),
    [
        (None, 0.5),
        (True, 0.5),
        ("   ", 0.5),
        ("point-1", True),
        ("point-1", float("nan")),
        ("point-1", float("inf")),
    ],
)
@pytest.mark.parametrize(
    "profile",
    [
        ProcessingProfile.CLOUD,
        ProcessingProfile.HYBRID_LOCAL,
    ],
)
def test_malformed_point_identity_or_score_fails_closed(
    profile: ProcessingProfile,
    point_id: Any,
    score: Any,
) -> None:
    with pytest.raises(
        ApplicationException
    ) as exc_info:
        retrieve(
            profile=profile,
            point_payload=payload(profile),
            point_id=point_id,
            score=score,
        )

    assert (
        exc_info.value.code
        == invalid_result_code(profile)
    )


def candidate_metadata(
    candidate: (
        CloudRetrievalResult
        | HybridLocalRetrievalResult
    ),
) -> tuple[Any, ...]:
    return (
        candidate.point_id,
        candidate.document_id,
        candidate.processing_run_id,
        candidate.processing_profile,
        candidate.chunk_index,
        candidate.text,
        candidate.page,
        candidate.section,
        candidate.source,
        candidate.retrieval_score,
    )


def test_cross_profile_fusion_preserves_complete_provenance_without_leakage(
) -> None:
    document_a = CloudRetrievalResult(
        point_id="a-4",
        retrieval_score=0.11,
        document_id=10,
        processing_run_id=101,
        processing_profile=ProcessingProfile.CLOUD,
        chunk_index=4,
        text="A chunk 4",
        page=4,
        section="A section",
        source="A.pdf",
    )

    document_b = HybridLocalRetrievalResult(
        point_id="b-8",
        retrieval_score=9.5,
        document_id=20,
        processing_run_id=202,
        processing_profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
        chunk_index=8,
        text="B chunk 8",
        page=8,
        section="B section",
        source="B.pdf",
    )

    document_c = CloudRetrievalResult(
        point_id="c-2",
        retrieval_score=-0.7,
        document_id=30,
        processing_run_id=303,
        processing_profile=ProcessingProfile.CLOUD,
        chunk_index=2,
        text="C chunk 2",
        page=2,
        section=None,
        source="C.pdf",
    )

    originals = [
        document_a,
        document_b,
        document_c,
    ]

    snapshots = [
        candidate_metadata(candidate)
        for candidate in originals
    ]

    results = (
        CrossProfileRankFusionService(rrf_k=60).fuse(
            ranked_result_collections=[
                [document_a],
                [document_b],
                [document_c],
            ],
            limit=3,
        )
    )

    assert len(results) == 3

    for result, original, snapshot in zip(
        results,
        originals,
        snapshots,
        strict=True,
    ):
        assert result is original
        assert (
            candidate_metadata(result)
            == snapshot
        )
