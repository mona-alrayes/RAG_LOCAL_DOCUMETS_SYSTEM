"""تجربة محلية: قراءة إجابات محفوظة كـ JSON من stdin وإعادة تقييمها دون تعديل قاعدة البيانات.

شغّل من fastapi-app باستخدام .venv/bin/python scripts/compare_saved_answers.py.
كل سجل يتطلب question/reference_answer/generated_answer ويمكن أن يتضمن question_id/evaluation_run_id.
النتائج تُطبع كـ JSONL ويمكن حفظها في تقرير منفصل.
"""

import asyncio
import json
import sys
from functools import partial
from urllib.parse import urlparse

import httpx2

from app.core.config import Settings
from app.generation.ollama import OllamaLLMProvider
from app.generation.prompt_budget import prompt_tokens
from app.services.generation_metrics import (
    CORRECTNESS_RUBRIC_VERSION,
    GenerationMetricsEvaluator,
)


async def main():
    rows = json.load(sys.stdin)
    settings = Settings()
    # يمنع إرسال هذه الإجابات إلى عنوان خارجي بالخطأ أثناء تجربة المقيّم المحلي.
    if urlparse(settings.ollama_base_url).hostname not in {
        "localhost",
        "127.0.0.1",
        "::1",
    }:
        raise ValueError("This experiment requires a loopback Ollama URL.")

    class DiagnosticProvider(OllamaLLMProvider):
        # تشخيص محلي اختياري فقط؛ لا نسجل نصوص الوثائق في سجلات التطبيق.
        async def stream(self, **kwargs):
            self.last_output = ""
            self.last_error = None
            try:
                async for token in super().stream(**kwargs):
                    self.last_output += token
                    yield token
            except Exception as exc:
                self.last_error = f"{type(exc).__name__}: {exc}"
                raise

    provider = DiagnosticProvider(
        client_factory=httpx2.AsyncClient,
        base_url=settings.ollama_base_url,
        model=settings.local_llm_model,
        keep_alive="0",
        timeout_seconds=settings.ollama_request_timeout_seconds,
        num_ctx=settings.local_llm_num_ctx,
        max_tokens=settings.rag_generation_max_tokens,
        count_prompt_tokens=partial(
            prompt_tokens, tokenizer_path=settings.local_llm_tokenizer_path
        ),
    )
    evaluator = GenerationMetricsEvaluator()
    for row in rows:
        result = await evaluator.evaluate_correctness(
            provider=provider,
            question=row["question"],
            generated_answer=row["generated_answer"],
            reference_answer=row["reference_answer"],
        )
        if "--debug" in sys.argv and result.status == "failed":
            print(
                json.dumps(
                    {
                        "diagnostic_error": provider.last_error,
                        "raw_judge_output": provider.last_output,
                    },
                    ensure_ascii=False,
                ),
                file=sys.stderr,
                flush=True,
            )
        print(
            json.dumps(
                {
                    "run": row.get("evaluation_run_id"),
                    "question_id": row.get("question_id"),
                    "rubric": CORRECTNESS_RUBRIC_VERSION,
                    "model": settings.local_llm_model,
                    "judge_temperature": 0,
                    "result": result.model_dump(),
                },
                ensure_ascii=False,
            ),
            flush=True,
        )


if __name__ == "__main__":
    asyncio.run(main())
