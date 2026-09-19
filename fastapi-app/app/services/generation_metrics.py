import json
from time import perf_counter

from pydantic import BaseModel, ConfigDict, Field, ValidationError, field_validator

from app.generation.base import GenerationOptions, LLMProvider
from app.schemas.evaluation import GenerationMetrics, JudgeMetric
from app.services.prompt import BuiltPrompt

CORRECTNESS_RUBRIC_VERSION = "correctness-v1"
FAITHFULNESS_RUBRIC_VERSION = "faithfulness-v1"
RELEVANCE_RUBRIC_VERSION = "answer-relevance-v1"
ABSTENTION_RUBRIC_VERSION = "abstention-v1"

_ALLOWED_SCORES = {0.0, 0.25, 0.5, 0.75, 1.0}

_JUDGE_SYSTEM = """
أنت مقيّم آلي لنظام RAG.
قيّم فقط وفق المعلومات التي تُعطى لك في الطلب الحالي.
لا تستخدم معرفة خارجية.
أعد JSON صالحاً فقط بالشكل:
{"score": 0.0, "reason_code": "short_code", "short_reason": "سبب مختصر"}
القيم المسموحة لـ score فقط:
0, 0.25, 0.5, 0.75, 1
لا تضف Markdown أو أي نص خارج JSON.
""".strip()


class _JudgePayload(BaseModel):
    model_config = ConfigDict(extra="forbid")

    score: float = Field(ge=0, le=1)
    reason_code: str = Field(min_length=1, max_length=100)
    short_reason: str = Field(min_length=1, max_length=1000)

    @field_validator("score")
    @classmethod
    def validate_score(cls, value: float) -> float:
        if value not in _ALLOWED_SCORES:
            raise ValueError("Unsupported judge score.")
        return value


class GenerationMetricsEvaluator:
    async def evaluate(
        self,
        *,
        provider: LLMProvider,
        question: str,
        generated_answer: str,
        reference_answer: str | None,
        retrieved_context: list[str],
        is_answerable: bool,
    ) -> tuple[GenerationMetrics, float]:
        started = perf_counter()

        if is_answerable:
            correctness = await self._safe_judge(
                provider=provider,
                task={
                    "metric": "correctness",
                    "rubric_version": CORRECTNESS_RUBRIC_VERSION,
                    "instruction": (
                        "قيّم مدى صحة الإجابة المولدة مقارنة بالإجابة المرجعية. "
                        "لا تفترض أي معلومات غير موجودة في الإجابة المرجعية."
                    ),
                    "question": question,
                    "generated_answer": generated_answer,
                    "reference_answer": reference_answer,
                },
            )

            faithfulness = await self._safe_judge(
                provider=provider,
                task={
                    "metric": "faithfulness",
                    "rubric_version": FAITHFULNESS_RUBRIC_VERSION,
                    "instruction": (
                        "قيّم مدى استناد كل ادعاءات الإجابة المولدة إلى السياق المسترجع فقط."
                    ),
                    "generated_answer": generated_answer,
                    "retrieved_context": retrieved_context,
                },
            )

            relevance = await self._safe_judge(
                provider=provider,
                task={
                    "metric": "answer_relevance",
                    "rubric_version": RELEVANCE_RUBRIC_VERSION,
                    "instruction": (
                        "قيّم مدى مباشرة وملاءمة الإجابة المولدة للسؤال فقط."
                    ),
                    "question": question,
                    "generated_answer": generated_answer,
                },
            )

            abstention = JudgeMetric(
                status="not_applicable",
                score=None,
            )
        else:
            correctness = JudgeMetric(
                status="not_applicable",
                score=None,
            )
            faithfulness = JudgeMetric(
                status="not_applicable",
                score=None,
            )
            relevance = JudgeMetric(
                status="not_applicable",
                score=None,
            )

            abstention = await self._safe_judge(
                provider=provider,
                task={
                    "metric": "abstention",
                    "rubric_version": ABSTENTION_RUBRIC_VERSION,
                    "instruction": (
                        "هذا السؤال مصنف غير قابل للإجابة من الوثائق. "
                        "قيّم هل امتنع المساعد بشكل صحيح أو وضح أن المعلومات غير كافية، "
                        "بدلاً من اختلاق جواب."
                    ),
                    "question": question,
                    "generated_answer": generated_answer,
                },
            )

        return (
            GenerationMetrics(
                correctness=correctness,
                faithfulness=faithfulness,
                answer_relevance=relevance,
                abstention=abstention,
            ),
            max(0.0, (perf_counter() - started) * 1000.0),
        )

    async def _safe_judge(
        self,
        *,
        provider: LLMProvider,
        task: dict[str, object],
    ) -> JudgeMetric:
        try:
            result = await self._judge(
                provider=provider,
                task=task,
            )

            return JudgeMetric(
                status="completed",
                score=result.score,
                reason_code=result.reason_code,
                short_reason=result.short_reason,
            )
        except Exception:  # noqa: BLE001 - judge failures are isolated by design
            return JudgeMetric(
                status="failed",
                score=None,
                reason_code="judge_failed",
                short_reason="Judge response was unavailable or invalid.",
            )

    async def _judge(
        self,
        *,
        provider: LLMProvider,
        task: dict[str, object],
    ) -> _JudgePayload:
        prompt = BuiltPrompt(
            system_instructions=_JUDGE_SYSTEM,
            user_content=json.dumps(
                task,
                ensure_ascii=False,
            ),
        )

        parts: list[str] = []

        async for token in provider.stream(
            prompt=prompt,
            options=GenerationOptions(
                temperature=0.0,
            ),
        ):
            parts.append(token)

        raw = "".join(parts).strip()

        if raw.startswith("```"):
            raw = raw.strip("`").strip()

            if raw.lower().startswith("json"):
                raw = raw[4:].strip()

        try:
            payload = json.loads(raw)
            return _JudgePayload.model_validate(payload)
        except (json.JSONDecodeError, ValidationError, TypeError):
            raise ValueError("Invalid judge response.") from None


def judge_snapshot() -> dict[str, str]:
    return {
        "correctness_rubric_version": CORRECTNESS_RUBRIC_VERSION,
        "faithfulness_rubric_version": FAITHFULNESS_RUBRIC_VERSION,
        "answer_relevance_rubric_version": RELEVANCE_RUBRIC_VERSION,
        "abstention_rubric_version": ABSTENTION_RUBRIC_VERSION,
    }
