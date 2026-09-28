from app.processing.base import ProcessingProfile
from app.schemas.rag import RecentCompletedTurn
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
)
from app.services.context import ContextService
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
)


def _cloud_result(
    *,
    point_id: str = "cloud-1",
) -> CloudRetrievalResult:
    return CloudRetrievalResult(
        point_id=point_id,
        retrieval_score=0.9,
        document_id=10,
        processing_run_id=100,
        processing_profile=ProcessingProfile.CLOUD,
        chunk_index=0,
        text="trusted cloud chunk",
        page=1,
        section="intro",
        source="cloud.pdf",
    )


def _local_result(
    *,
    point_id: str = "local-1",
) -> HybridLocalRetrievalResult:
    return HybridLocalRetrievalResult(
        point_id=point_id,
        retrieval_score=0.8,
        document_id=20,
        processing_run_id=200,
        processing_profile=ProcessingProfile.HYBRID_LOCAL,
        chunk_index=1,
        text="trusted local chunk",
        page=2,
        section=None,
        source="local.pdf",
    )


def test_builds_context_with_retrieved_chunks_only() -> None:
    service = ContextService()
    chunk = _cloud_result()

    context = service.build(
        retrieved_chunks=[chunk],
        recent_completed_turns=[],
    )

    assert context.retrieved_chunks == (chunk,)
    assert context.recent_completed_turns == ()
    assert context.retrieved_chunks[0] is chunk


def test_composes_recent_completed_turns_in_given_order() -> None:
    service = ContextService()

    first_turn = RecentCompletedTurn(
        user="first user",
        assistant="first assistant",
    )
    second_turn = RecentCompletedTurn(
        user="second user",
        assistant="second assistant",
    )

    context = service.build(
        retrieved_chunks=[],
        recent_completed_turns=[
            first_turn,
            second_turn,
        ],
    )

    assert context.recent_completed_turns == (
        first_turn,
        second_turn,
    )


def test_preserves_retrieved_chunk_identity_and_provenance() -> None:
    service = ContextService()

    cloud = _cloud_result()
    local = _local_result()

    context = service.build(
        retrieved_chunks=[cloud, local],
        recent_completed_turns=[],
    )

    assert context.retrieved_chunks[0] is cloud
    assert context.retrieved_chunks[1] is local

    assert context.retrieved_chunks[0].point_id == "cloud-1"
    assert context.retrieved_chunks[0].source == "cloud.pdf"

    assert context.retrieved_chunks[1].point_id == "local-1"
    assert context.retrieved_chunks[1].source == "local.pdf"


def test_build_is_deterministic_for_same_inputs() -> None:
    service = ContextService()

    cloud = _cloud_result()
    local = _local_result()

    turns = [
        RecentCompletedTurn(
            user="question one",
            assistant="answer one",
        ),
        RecentCompletedTurn(
            user="question two",
            assistant="answer two",
        ),
    ]

    first = service.build(
        retrieved_chunks=[cloud, local],
        recent_completed_turns=turns,
    )

    second = service.build(
        retrieved_chunks=[cloud, local],
        recent_completed_turns=turns,
    )

    assert first == second

    assert first.retrieved_chunks[0] is cloud
    assert second.retrieved_chunks[0] is cloud

    assert first.retrieved_chunks[1] is local
    assert second.retrieved_chunks[1] is local
