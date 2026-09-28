import asyncio
import json

import pytest

from app.services.generation_metrics import (
    GenerationMetricsEvaluator,
    _evidence_parts,
    _IndexedCorrectnessPayload,
)


def resolve(
    checks,
    reference="Twenty years. Another condition.",
    answer="20 years. Other condition.",
):
    # النصوص المعروضة تُستخرج من المدخل الأصلي؛ الموديل يختار أرقاماً فقط.
    return _IndexedCorrectnessPayload.model_validate(
        {"checks": checks, "short_reason": "Checked."}
    ).resolve(_evidence_parts(reference, 12), _evidence_parts(answer, 64))


def test_paraphrase_evidence_comes_from_original_text():
    result = resolve(
        [
            {"reference_id": 1, "status": "supported", "answer_ids": [1]},
            {"reference_id": 2, "status": "supported", "answer_ids": [2]},
        ]
    )
    assert result.score == 1
    assert result.checks[0].reference_quote == "Twenty years."
    assert result.checks[0].answer_quote == "20 years."


@pytest.mark.parametrize(
    "status,expected",
    [
        ("supported", 1),
        ("partial", 0.75),
        ("missing", 0.5),
        ("contradicted", 0.5),
    ],
)
def test_scores_are_calculated_from_complete_reference_coverage(status, expected):
    result = resolve(
        [
            {"reference_id": 1, "status": "supported", "answer_ids": [1]},
            {
                "reference_id": 2,
                "status": status,
                "answer_ids": [] if status == "missing" else [2],
            },
        ]
    )
    assert result.score == expected


@pytest.mark.parametrize(
    "checks",
    [
        [{"reference_id": 1, "status": "supported", "answer_ids": [1]}],
        [
            {"reference_id": 1, "status": "supported", "answer_ids": [1]},
            {"reference_id": 1, "status": "supported", "answer_ids": [1]},
        ],
        [
            {"reference_id": 1, "status": "supported", "answer_ids": [99]},
            {"reference_id": 2, "status": "supported", "answer_ids": [2]},
        ],
        [
            {"reference_id": 1, "status": "missing", "answer_ids": [1]},
            {"reference_id": 2, "status": "supported", "answer_ids": [2]},
        ],
        [
            {"reference_id": 1, "status": "supported", "answer_ids": []},
            {"reference_id": 2, "status": "supported", "answer_ids": [2]},
        ],
    ],
)
def test_omitted_duplicate_or_fabricated_evidence_ids_are_rejected(checks):
    with pytest.raises(ValueError):
        resolve(checks)


def test_long_reference_is_grouped_without_losing_content():
    text = " ".join(f"Fact {i}." for i in range(40))
    parts = _evidence_parts(text, 12)
    assert len(parts) <= 12
    assert " ".join(" ".join(part["text"].split()) for part in parts) == text


def test_grouped_evidence_preserves_original_delimiters_verbatim():
    # الاقتباس المتصل يجب أن يكون جزءاً حرفياً من الأصل، حتى عند تجميع الجمل والأسطر.
    reference = "  ".join(f"Point {i}." for i in range(13))
    assert all(part["text"] in reference for part in _evidence_parts(reference, 12))
    answer = "Line one.\r\n\r\nLine two."
    assert _evidence_parts(answer, 1, sentences=False)[0]["text"] == answer
    reference = "لا.\n\nلا يجوز التكرار."
    assert _evidence_parts(reference, 12)[0]["text"] == reference


def test_multiple_answer_excerpts_keep_original_order():
    result = resolve(
        [{"reference_id": 1, "status": "supported", "answer_ids": [2, 1]}],
        reference="Both conditions.",
    )
    assert result.checks[0].answer_quote == "20 years.\n[…]\nOther condition."


def test_one_format_retry_can_recover_without_changing_evidence():
    class Provider:
        calls = 0

        async def stream(self, *, prompt, options):
            self.calls += 1
            task = json.loads(prompt.user_content)
            assert task["answer_parts"] == [{"id": 1, "text": "20 years"}]
            assert options.temperature == 0
            if self.calls == 1:
                yield "not JSON"
            else:
                assert "format_retry" in task
                yield json.dumps(
                    {
                        "checks": [
                            {
                                "reference_id": 1,
                                "status": "supported",
                                "answer_ids": [1],
                            }
                        ],
                        "short_reason": "Equivalent.",
                    }
                )

    provider = Provider()
    result = asyncio.run(
        GenerationMetricsEvaluator().evaluate_correctness(
            provider=provider,
            question="When?",
            reference_answer="Twenty years",
            generated_answer="20 years",
        )
    )
    assert result.score == 1
    assert provider.calls == 2


def test_evaluator_persists_checks_and_isolates_invalid_judgments():
    class Provider:
        def __init__(self, invalid=False):
            self.invalid = invalid
            self.temperatures = []

        async def stream(self, *, prompt, options):
            self.temperatures.append(options.temperature)
            task = json.loads(prompt.user_content)
            if task["metric"] == "correctness":
                yield json.dumps(
                    {
                        "checks": [
                            {
                                "reference_id": 1,
                                "status": "supported",
                                "answer_ids": [99 if self.invalid else 1],
                            }
                        ],
                        "short_reason": "Equivalent.",
                    }
                )
            else:
                yield json.dumps(
                    {"score": 1, "reason_code": "pass", "short_reason": "Supported."}
                )

    for invalid in [False, True]:
        provider = Provider(invalid)
        result, _ = asyncio.run(
            GenerationMetricsEvaluator().evaluate(
                provider=provider,
                question="When?",
                generated_answer="20 years",
                reference_answer="Twenty years",
                retrieved_context=["Twenty years"],
                is_answerable=True,
            )
        )
        # محاولة إصلاح واحدة فقط؛ الفشل النهائي يبقى null ولا يتحول إلى صفر.
        assert provider.temperatures == ([0, 0, 0, 0] if invalid else [0, 0, 0])
        assert result.faithfulness.score == 1
        assert result.correctness.status == ("failed" if invalid else "completed")
        assert len(result.correctness.checks) == (0 if invalid else 1)


def test_valid_missing_judgment_is_not_selectively_rejudged_until_accepted():
    class Provider:
        calls = 0

        async def stream(self, *, prompt, options):
            self.calls += 1
            assert len(json.loads(prompt.user_content)["reference_parts"]) == 2
            assert options.temperature == 0
            if self.calls == 1:
                checks = [
                    {"reference_id": 1, "status": "supported", "answer_ids": [1]},
                    {"reference_id": 2, "status": "missing", "answer_ids": []},
                ]
            else:
                # رد ثانٍ متساهل يكشف إعادة الحكم الانتقائية التي ترفع الدرجة بلا ضمان.
                checks = [{"reference_id": 2, "status": "supported", "answer_ids": [2]}]
            yield json.dumps({"checks": checks, "short_reason": "Reviewed."})

    provider = Provider()
    result = asyncio.run(
        GenerationMetricsEvaluator().evaluate_correctness(
            provider=provider,
            question="When?",
            reference_answer="Twenty years. Another condition.",
            generated_answer="20 years.\nOther condition.",
        )
    )
    assert result.score == 0.5
    assert [check.status for check in result.checks] == ["supported", "missing"]
    assert provider.calls == 1


def test_yes_no_prefix_has_no_extra_weight_and_citations_stay_together():
    assert _evidence_parts("لا. لا يجوز التكرار.", 12) == [
        {"id": 1, "text": "لا. لا يجوز التكرار."}
    ]
    assert _evidence_parts("الحكم [المصدر، ص. 65].", 64, sentences=False) == [
        {"id": 1, "text": "الحكم [المصدر، ص. 65]."}
    ]
