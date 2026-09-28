from types import SimpleNamespace

from app.core.config import Settings
from app.processing.base import ProcessingProfile
from app.schemas.evaluation import GoldenEvidence
from app.schemas.rag import DocumentTarget
from app.services.golden_evidence_binding import (
    GoldenEvidenceBinder,
)


class FakeQdrantClient:
    def __init__(self, points):
        self.points = points
        self.calls = []

    def scroll(self, collection_name, **kwargs):
        self.calls.append(
            {
                "collection_name": collection_name,
                **kwargs,
            }
        )
        return self.points, None


def point(
    *,
    point_id,
    chunk_index,
    text,
    page=5,
    section="المادة 10",
    source="law.pdf",
):
    return SimpleNamespace(
        id=point_id,
        payload={
            "chunk_index": chunk_index,
            "text": text,
            "page": page,
            "section": section,
            "source": source,
        },
    )


def target():
    return DocumentTarget(
        document_id=12,
        processing_run_id=81,
        processing_profile=ProcessingProfile.CLOUD,
    )


def test_exact_evidence_is_bound_to_indexed_chunk():
    client = FakeQdrantClient(
        [
            point(
                point_id="p1",
                chunk_index=1,
                text=(
                    "يستحق الموظف إجازة سنوية "
                    "مدتها ثلاثون يوماً."
                ),
            ),
            point(
                point_id="p2",
                chunk_index=2,
                text="نص آخر لا يطابق الدليل.",
            ),
        ]
    )

    result = GoldenEvidenceBinder(
        settings=Settings(_env_file=None),
        client=client,
    ).bind(
        user_id=7,
        targets=[target()],
        evidence=[
            GoldenEvidence(
                source="law.pdf",
                page=5,
                section="المادة 10",
                evidence_text=(
                    "يستحق الموظف إجازة سنوية "
                    "مدتها ثلاثون يوماً."
                ),
            )
        ],
        is_answerable=True,
    )

    assert result.status == "bound"
    assert len(result.relevant_chunks) == 1
    assert result.relevant_chunks[0].point_id == "p1"
    assert result.relevant_chunks[0].chunk_index == 1
    assert result.evidence[0].score == 1.0

    filter_conditions = {
        condition.key: condition.match.value
        for condition in client.calls[0][
            "scroll_filter"
        ].must
    }

    assert filter_conditions == {
        "user_id": 7,
        "document_id": 12,
        "processing_run_id": 81,
        "processing_profile": "cloud",
    }


def test_evidence_can_bind_across_adjacent_chunks():
    client = FakeQdrantClient(
        [
            point(
                point_id="p10",
                chunk_index=10,
                text=(
                    "يستحق الموظف إجازة سنوية "
                    "مدتها"
                ),
            ),
            point(
                point_id="p11",
                chunk_index=11,
                text=(
                    "ثلاثون يوماً وفق أحكام "
                    "القانون"
                ),
            ),
        ]
    )

    result = GoldenEvidenceBinder(
        settings=Settings(_env_file=None),
        client=client,
    ).bind(
        user_id=7,
        targets=[target()],
        evidence=[
            GoldenEvidence(
                source="law.pdf",
                page=5,
                section="المادة 10",
                evidence_text=(
                    "يستحق الموظف إجازة سنوية "
                    "مدتها ثلاثون يوماً وفق أحكام "
                    "القانون"
                ),
            )
        ],
        is_answerable=True,
    )

    assert result.status == "bound"
    assert [
        chunk.chunk_index
        for chunk in result.relevant_chunks
    ] == [10, 11]


def test_evidence_text_can_bind_when_markdown_section_is_a_parent_heading():
    evidence_text = (
        "تلتزم الجهة العامة بتشغيل ذوي الإعاقة "
        "من بين المتقدمين المستوفين لشروط التوظيف."
    )
    client = FakeQdrantClient(
        [
            point(
                point_id="p29",
                chunk_index=29,
                page=11,
                section="الفصل الرابع الشروط العامة لإشغال الوظائف",
                text=evidence_text,
            )
        ]
    )

    result = GoldenEvidenceBinder(
        settings=Settings(_env_file=None),
        client=client,
    ).bind(
        user_id=7,
        targets=[target()],
        evidence=[
            GoldenEvidence(
                source="law.pdf",
                page=11,
                section="المادة 11",
                evidence_text=evidence_text,
            )
        ],
        is_answerable=True,
    )

    assert result.status == "bound"
    assert [chunk.point_id for chunk in result.relevant_chunks] == ["p29"]
    assert result.evidence[0].score == 1.0


def test_matching_section_breaks_a_tie_between_duplicate_text():
    duplicated = "تبت اللجنة بالاعتراض خلال سبعة أيام."
    client = FakeQdrantClient(
        [
            point(
                point_id="wrong-section",
                chunk_index=8,
                section="لجنة التسريح الطبية",
                text=duplicated,
            ),
            point(
                point_id="matching-section",
                chunk_index=18,
                section="المادة 52 / لجنة الاعتراضات",
                text=duplicated,
            ),
        ]
    )

    result = GoldenEvidenceBinder(
        settings=Settings(_env_file=None),
        client=client,
    ).bind(
        user_id=7,
        targets=[target()],
        evidence=[
            GoldenEvidence(
                source="law.pdf",
                page=5,
                section="المادة 52",
                evidence_text=duplicated,
            )
        ],
        is_answerable=True,
    )

    assert result.status == "bound"
    assert [chunk.point_id for chunk in result.relevant_chunks] == [
        "matching-section"
    ]


def test_adjacent_overlap_copy_is_not_treated_as_ambiguous_evidence():
    duplicated_by_overlap = "Closing bridge sentence."
    client = FakeQdrantClient(
        [
            point(
                point_id="original",
                chunk_index=10,
                text="Background. Closing bridge sentence.",
            ),
            point(
                point_id="overlap-copy",
                chunk_index=11,
                text="Closing bridge sentence. Next topic.",
            ),
        ]
    )

    result = GoldenEvidenceBinder(
        settings=Settings(_env_file=None),
        client=client,
    ).bind(
        user_id=7,
        targets=[target()],
        evidence=[
            GoldenEvidence(
                source="law.pdf",
                page=5,
                section="المادة 10",
                evidence_text=duplicated_by_overlap,
            )
        ],
        is_answerable=True,
    )

    assert result.status == "bound"
    assert result.evidence[0].status == "bound"
    assert result.evidence[0].competing_matches == 0


def test_ambiguous_evidence_requires_review_instead_of_guessing():
    duplicated = (
        "يستحق الموظف إجازة سنوية "
        "مدتها ثلاثون يوماً."
    )

    client = FakeQdrantClient(
        [
            point(
                point_id="p1",
                chunk_index=1,
                text=duplicated,
            ),
            point(
                point_id="p20",
                chunk_index=20,
                text=duplicated,
            ),
        ]
    )

    result = GoldenEvidenceBinder(
        settings=Settings(_env_file=None),
        client=client,
    ).bind(
        user_id=7,
        targets=[target()],
        evidence=[
            GoldenEvidence(
                source="law.pdf",
                page=5,
                section="المادة 10",
                evidence_text=duplicated,
            )
        ],
        is_answerable=True,
    )

    assert result.status == "needs_review"
    assert result.evidence[0].status == "needs_review"
    assert result.evidence[0].competing_matches == 1


def test_unanswerable_question_does_not_read_qdrant():
    client = FakeQdrantClient([])

    result = GoldenEvidenceBinder(
        settings=Settings(_env_file=None),
        client=client,
    ).bind(
        user_id=7,
        targets=[target()],
        evidence=[],
        is_answerable=False,
    )

    assert result.status == "not_applicable"
    assert result.relevant_chunks == []
    assert result.evidence == []
    assert client.calls == []
