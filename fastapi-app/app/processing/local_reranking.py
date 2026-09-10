import math
from dataclasses import dataclass, replace
from importlib import import_module
from typing import Any

from app.core.exceptions import ApplicationException
from app.runtime.model_coordinator import LocalModelCoordinator
from app.runtime.models import (
    LocalRuntimeSnapshot,
    RuntimeBackend,
    RuntimeDtype,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
)

LOCAL_RERANK_MAX_LENGTH = 1024


@dataclass(slots=True)
class _LocalBgeRerankerResources:
    tokenizer: Any
    model: Any
    torch: Any


class LocalBgeReranker:
    def __init__(
        self,
        model: str,
        runtime: LocalRuntimeSnapshot,
        coordinator: LocalModelCoordinator,
        keep_alive_seconds: float = 0.0,
    ) -> None:
        if (
            not runtime.ready
            or runtime.selected_backend is None
            or runtime.selected_dtype is None
        ):
            raise ApplicationException(
                code="local_reranker_runtime_unavailable",
                message="Local reranker runtime is not ready.",
            )

        self._model_id = model
        self._device = self._resolve_torch_device(
            runtime.selected_backend
        )
        self._dtype = runtime.selected_dtype
        if keep_alive_seconds < 0:
            raise ValueError(
                "keep_alive_seconds must not be negative."
            )

        self._coordinator = coordinator
        self._keep_alive_seconds = (
            keep_alive_seconds
        )

    def rerank(
        self,
        *,
        question: str,
        candidates: list[HybridLocalRetrievalResult],
        limit: int,
    ) -> list[HybridLocalRetrievalResult]:
        if (
            isinstance(limit, bool)
            or not isinstance(limit, int)
            or limit < 1
        ):
            raise ApplicationException(
                code="local_reranker_limit_invalid",
                message="Local reranker limit must be a positive integer.",
            )

        if not candidates:
            return []

        if (
            not isinstance(question, str)
            or not question.strip()
        ):
            raise ApplicationException(
                code="local_reranker_question_invalid",
                message="Local reranker question must not be blank.",
            )

        with self._coordinator.lease(
            model_id=self._model_id,
            loader=self._load_resources,
            keep_alive_seconds=(
                self._keep_alive_seconds
            ),
        ) as lease:
            scores: list[float] = []
            # Bound activation memory independently of the document count.
            for start in range(0, len(candidates), 4):
                batch = candidates[start:start + 4]
                raw_scores = self._score_with_resources(
                    resources=lease.resource, question=question, candidates=batch,
                )
                scores.extend(self._validate_scores(
                    scores=raw_scores, expected_count=len(batch),
                ))

        ranked_indexes = sorted(
            range(len(candidates)),
            key=lambda index: scores[index],
            reverse=True,
        )

        return [
            replace(
                candidates[index],
                reranker_score=scores[index],
            )
            for index in ranked_indexes[:limit]
        ]

    def _load_resources(
        self,
    ) -> _LocalBgeRerankerResources:
        try:
            torch = import_module("torch")
            transformers = import_module("transformers")

            torch_dtype = (
                torch.float16
                if self._dtype is RuntimeDtype.FP16
                else torch.float32
            )

            tokenizer = (
                transformers.AutoTokenizer.from_pretrained(
                    self._model_id
                )
            )

            model = (
                transformers
                .AutoModelForSequenceClassification
                .from_pretrained(
                    self._model_id,
                    torch_dtype=torch_dtype,
                )
            )

            model = model.to(self._device)
            model.eval()

            return _LocalBgeRerankerResources(
                tokenizer=tokenizer,
                model=model,
                torch=torch,
            )
        except Exception as exc:
            raise ApplicationException(
                code="local_reranker_model_failed",
                message="Local reranker model failed to load.",
            ) from exc

    def _score_with_resources(
        self,
        *,
        resources: _LocalBgeRerankerResources,
        question: str,
        candidates: list[HybridLocalRetrievalResult],
    ) -> object:
        try:
            pairs = [
                [question, candidate.text]
                for candidate in candidates
            ]

            inputs = resources.tokenizer(
                pairs,
                padding=True,
                truncation=True,
                max_length=LOCAL_RERANK_MAX_LENGTH,
                return_tensors="pt",
            )

            inputs = {
                key: value.to(self._device)
                for key, value in inputs.items()
            }

            with resources.torch.inference_mode():
                outputs = resources.model(
                    **inputs,
                    return_dict=True,
                )

                logits = outputs.logits.view(-1)

            return (
                logits
                .detach()
                .cpu()
                .float()
                .tolist()
            )
        except Exception as exc:
            raise ApplicationException(
                code="local_reranker_model_failed",
                message="Local reranker model inference failed.",
            ) from exc

    @staticmethod
    def _validate_scores(
        *,
        scores: object,
        expected_count: int,
    ) -> list[float]:
        if (
            not isinstance(scores, list)
            or len(scores) != expected_count
        ):
            raise ApplicationException(
                code="local_reranker_result_invalid",
                message="Local reranker result is invalid.",
            )

        validated_scores: list[float] = []

        for score in scores:
            if (
                isinstance(score, bool)
                or not isinstance(score, (int, float))
                or not math.isfinite(float(score))
            ):
                raise ApplicationException(
                    code="local_reranker_result_invalid",
                    message="Local reranker result is invalid.",
                )

            validated_scores.append(float(score))

        return validated_scores

    @staticmethod
    def _resolve_torch_device(
        backend: RuntimeBackend,
    ) -> str:
        if backend in {
            RuntimeBackend.CUDA,
            RuntimeBackend.ROCM,
        }:
            return "cuda"

        return backend.value
