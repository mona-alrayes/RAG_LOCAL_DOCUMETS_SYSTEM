import pytest

from app.core.exceptions import ApplicationException
from app.processing.base import ProcessingProfile
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
)
from app.services.cross_profile_rank_fusion import (
    CrossProfileRankFusionService,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
)


def _cloud_result(
    *,
    point_id: str,
    score: float,
    document_id: int = 10,
    processing_run_id: int = 100,
    chunk_index: int = 0,
) -> CloudRetrievalResult:
    return CloudRetrievalResult(
        point_id=point_id,
        retrieval_score=score,
        document_id=document_id,
        processing_run_id=processing_run_id,
        processing_profile=ProcessingProfile.CLOUD,
        chunk_index=chunk_index,
        text=f"Cloud {point_id}",
        page=1,
        section=None,
        source="cloud.pdf",
    )


def _local_result(
    *,
    point_id: str,
    score: float,
    document_id: int = 20,
    processing_run_id: int = 200,
    chunk_index: int = 0,
) -> HybridLocalRetrievalResult:
    return HybridLocalRetrievalResult(
        point_id=point_id,
        retrieval_score=score,
        document_id=document_id,
        processing_run_id=processing_run_id,
        processing_profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
        chunk_index=chunk_index,
        text=f"Local {point_id}",
        page=1,
        section=None,
        source="local.pdf",
    )


def test_fuses_mixed_profiles_by_rank_not_raw_score():
    service = CrossProfileRankFusionService(rrf_k=60)

    cloud_first = _cloud_result(
        point_id="cloud-1",
        score=-1_000_000.0,
        chunk_index=0,
    )
    cloud_second = _cloud_result(
        point_id="cloud-2",
        score=1_000_000.0,
        chunk_index=1,
    )

    local_first = _local_result(
        point_id="local-1",
        score=-999_999_999.0,
        chunk_index=0,
    )
    local_second = _local_result(
        point_id="local-2",
        score=999_999_999.0,
        chunk_index=1,
    )

    result = service.fuse(
        ranked_result_collections=[
            [cloud_first, cloud_second],
            [local_first, local_second],
        ],
        limit=3,
    )

    assert result == [
        cloud_first,
        local_first,
        cloud_second,
    ]

    assert result[0] is cloud_first
    assert result[1] is local_first
    assert result[2] is cloud_second

    assert cloud_first.retrieval_score == -1_000_000.0
    assert local_first.retrieval_score == -999_999_999.0


def test_fusion_is_deterministic():
    service = CrossProfileRankFusionService(rrf_k=60)

    cloud = [
        _cloud_result(
            point_id="cloud-1",
            score=0.1,
        ),
        _cloud_result(
            point_id="cloud-2",
            score=0.2,
            chunk_index=1,
        ),
    ]

    local = [
        _local_result(
            point_id="local-1",
            score=100.0,
        ),
        _local_result(
            point_id="local-2",
            score=200.0,
            chunk_index=1,
        ),
    ]

    first = service.fuse(
        ranked_result_collections=[
            cloud,
            local,
        ],
        limit=4,
    )

    second = service.fuse(
        ranked_result_collections=[
            cloud,
            local,
        ],
        limit=4,
    )

    assert first == second

    for first_candidate, second_candidate in zip(
        first,
        second,
        strict=True,
    ):
        assert first_candidate is second_candidate


def test_supports_multiple_documents_across_profiles():
    service = CrossProfileRankFusionService(rrf_k=60)

    cloud_document_a = _cloud_result(
        point_id="a-1",
        score=0.1,
        document_id=10,
        processing_run_id=100,
    )

    local_document_b = _local_result(
        point_id="b-1",
        score=5.0,
        document_id=20,
        processing_run_id=200,
    )

    cloud_document_c = _cloud_result(
        point_id="c-1",
        score=0.9,
        document_id=30,
        processing_run_id=300,
    )

    result = service.fuse(
        ranked_result_collections=[
            [cloud_document_a],
            [local_document_b],
            [cloud_document_c],
        ],
        limit=3,
    )

    assert result == [
        cloud_document_a,
        local_document_b,
        cloud_document_c,
    ]

    assert result[0].document_id == 10
    assert result[1].document_id == 20
    assert result[2].document_id == 30

    assert (
        result[0].processing_run_id
        == 100
    )
    assert (
        result[1].processing_run_id
        == 200
    )
    assert (
        result[2].processing_run_id
        == 300
    )


@pytest.mark.parametrize(
    "ranked_results",
    [
        [
            _cloud_result(
                point_id="cloud-only-1",
                score=0.4,
            ),
            _cloud_result(
                point_id="cloud-only-2",
                score=0.3,
                chunk_index=1,
            ),
        ],
        [
            _local_result(
                point_id="local-only-1",
                score=10.0,
            ),
            _local_result(
                point_id="local-only-2",
                score=5.0,
                chunk_index=1,
            ),
        ],
    ],
)
def test_single_profile_preserves_existing_order(
    ranked_results,
):
    service = CrossProfileRankFusionService(rrf_k=60)

    result = service.fuse(
        ranked_result_collections=[
            ranked_results
        ],
        limit=2,
    )

    assert result[0] is ranked_results[0]
    assert result[1] is ranked_results[1]


@pytest.mark.parametrize(
    "limit",
    [
        0,
        -1,
        True,
        1.5,
    ],
)
def test_invalid_limit_fails_closed(limit):
    service = CrossProfileRankFusionService(rrf_k=60)

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        service.fuse(
            ranked_result_collections=[],
            limit=limit,
        )

    assert (
        exc_info.value.code
        == "cross_profile_rank_fusion_limit_invalid"
    )


def test_malformed_candidate_fails_closed():
    service = CrossProfileRankFusionService(rrf_k=60)

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        service.fuse(
            ranked_result_collections=[
                ["invalid-candidate"]
            ],
            limit=1,
        )

    assert (
        exc_info.value.code
        == "cross_profile_rank_fusion_input_invalid"
    )


def test_wrong_profile_candidate_fails_closed():
    service = CrossProfileRankFusionService(rrf_k=60)

    invalid_candidate = CloudRetrievalResult(
        point_id="invalid-profile",
        retrieval_score=0.5,
        document_id=10,
        processing_run_id=100,
        processing_profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
        chunk_index=0,
        text="Invalid",
        page=1,
        section=None,
        source="invalid.pdf",
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        service.fuse(
            ranked_result_collections=[
                [invalid_candidate]
            ],
            limit=1,
        )

    assert (
        exc_info.value.code
        == "cross_profile_rank_fusion_input_invalid"
    )


def test_duplicate_candidate_fails_closed():
    service = CrossProfileRankFusionService(rrf_k=60)

    candidate = _cloud_result(
        point_id="duplicate",
        score=0.5,
    )

    with pytest.raises(
        ApplicationException
    ) as exc_info:
        service.fuse(
            ranked_result_collections=[
                [candidate],
                [candidate],
            ],
            limit=2,
        )

    assert (
        exc_info.value.code
        == (
            "cross_profile_rank_"
            "fusion_candidate_duplicate"
        )
    )
