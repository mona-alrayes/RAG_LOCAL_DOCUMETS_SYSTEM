from dataclasses import FrozenInstanceError

import pytest

from app.generation.result import (
    AnswerResult,
    AnswerTimings,
)
from app.processing.base import ProcessingProfile
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
)


def test_answer_result_preserves_trusted_sources_and_timings() -> None:
    source = CloudRetrievalResult(
        point_id="point-cloud-1",
        retrieval_score=0.91,
        document_id=12,
        processing_run_id=81,
        processing_profile=ProcessingProfile.CLOUD,
        chunk_index=48,
        text="Trusted retrieved chunk.",
        page=15,
        section="Results",
        source="study.pdf",
    )

    timings = AnswerTimings(
        query_embedding=30,
        retrieval=80,
        fusion=None,
        reranking=45,
        context_building=10,
        generation=320,
        total=485,
    )

    result = AnswerResult(
        answer="The supported answer.",
        sources=(source,),
        timings_ms=timings,
    )

    assert result.answer == "The supported answer."

    assert result.sources == (source,)
    assert result.sources[0].point_id == "point-cloud-1"
    assert result.sources[0].document_id == 12
    assert result.sources[0].processing_run_id == 81
    assert (
        result.sources[0].processing_profile
        is ProcessingProfile.CLOUD
    )
    assert result.sources[0].page == 15
    assert result.sources[0].section == "Results"
    assert result.sources[0].chunk_index == 48
    assert result.sources[0].source == "study.pdf"
    assert result.sources[0].retrieval_score == 0.91

    assert result.timings_ms.query_embedding == 30
    assert result.timings_ms.retrieval == 80
    assert result.timings_ms.fusion is None
    assert result.timings_ms.reranking == 45
    assert result.timings_ms.context_building == 10
    assert result.timings_ms.generation == 320
    assert result.timings_ms.total == 485


def test_answer_result_supports_mixed_profile_sources() -> None:
    cloud_source = CloudRetrievalResult(
        point_id="cloud-point",
        retrieval_score=0.88,
        document_id=10,
        processing_run_id=101,
        processing_profile=ProcessingProfile.CLOUD,
        chunk_index=3,
        text="Cloud indexed chunk.",
        page=2,
        section=None,
        source="cloud.pdf",
    )

    local_source = HybridLocalRetrievalResult(
        point_id="local-point",
        retrieval_score=0.84,
        document_id=20,
        processing_run_id=202,
        processing_profile=(
            ProcessingProfile.HYBRID_LOCAL
        ),
        chunk_index=7,
        text="Hybrid local indexed chunk.",
        page=4,
        section="Summary",
        source="local.pdf",
    )

    result = AnswerResult(
        answer="Combined supported answer.",
        sources=(
            cloud_source,
            local_source,
        ),
        timings_ms=AnswerTimings(
            query_embedding=40,
            retrieval=100,
            fusion=15,
            reranking=60,
            context_building=12,
            generation=350,
            total=577,
        ),
    )

    assert result.sources == (
        cloud_source,
        local_source,
    )

    assert (
        result.sources[0].processing_profile
        is ProcessingProfile.CLOUD
    )
    assert (
        result.sources[1].processing_profile
        is ProcessingProfile.HYBRID_LOCAL
    )

    assert result.sources[0].document_id == 10
    assert result.sources[1].document_id == 20


def test_answer_contract_is_immutable() -> None:
    result = AnswerResult(
        answer="Immutable answer.",
        sources=(),
        timings_ms=AnswerTimings(
            query_embedding=None,
            retrieval=None,
            fusion=None,
            reranking=None,
            context_building=None,
            generation=25,
            total=25,
        ),
    )

    with pytest.raises(FrozenInstanceError):
        result.answer = "Changed answer"  # type: ignore[misc]

    with pytest.raises(FrozenInstanceError):
        result.timings_ms.total = 99  # type: ignore[misc]
