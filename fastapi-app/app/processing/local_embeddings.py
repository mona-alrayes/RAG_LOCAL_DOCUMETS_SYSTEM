from dataclasses import dataclass
from importlib import import_module
from typing import Any, cast

from app.core.exceptions import ApplicationException
from app.processing.chunks import NormalizedChunk
from app.runtime.model_coordinator import LocalModelCoordinator
from app.runtime.models import LocalRuntimeSnapshot, RuntimeBackend, RuntimeDtype


LOCAL_EMBEDDING_DIMENSION = 1024


@dataclass(slots=True)
class _LocalBgeM3Resources:
    tokenizer: Any
    model: Any
    torch: Any


class LocalBgeM3Embedder:
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
                code="local_embedding_runtime_unavailable",
                message="Local embedding runtime is not ready.",
            )

        self._model_id = model
        self._device = self._resolve_torch_device(runtime.selected_backend)
        self._dtype = runtime.selected_dtype
        if keep_alive_seconds < 0:
            raise ValueError(
                "keep_alive_seconds must not be negative."
            )

        self._coordinator = coordinator
        self._keep_alive_seconds = (
            keep_alive_seconds
        )

    def embed(
        self,
        chunks: list[NormalizedChunk],
    ) -> list[list[float]]:
        if not chunks:
            return []

        return self.embed_texts(
            [chunk.text for chunk in chunks],
        )

    def embed_texts(
        self,
        texts: list[str],
    ) -> list[list[float]]:
        if not texts:
            return []

        with self._coordinator.lease(
            model_id=self._model_id,
            loader=self._load_resources,
            keep_alive_seconds=(
                self._keep_alive_seconds
            ),
        ) as lease:
            vectors = self._embed_with_resources(
                lease.resource,
                texts,
            )

        self._validate_vectors(
            vectors=vectors,
            expected_count=len(texts),
        )

        return vectors

    def _load_resources(self) -> _LocalBgeM3Resources:
        try:
            torch = import_module("torch")
            transformers = import_module("transformers")

            torch_dtype = (
                torch.float16
                if self._dtype is RuntimeDtype.FP16
                else torch.float32
            )

            tokenizer = transformers.AutoTokenizer.from_pretrained(
                self._model_id
            )

            model = transformers.AutoModel.from_pretrained(
                self._model_id,
                torch_dtype=torch_dtype,
            )

            model = model.to(self._device)
            model.eval()

            return _LocalBgeM3Resources(
                tokenizer=tokenizer,
                model=model,
                torch=torch,
            )
        except Exception as exc:
            raise ApplicationException(
                code="local_embedding_model_failed",
                message="Local embedding model failed to load.",
            ) from exc

    def _embed_with_resources(
        self,
        resources: _LocalBgeM3Resources,
        texts: list[str],
    ) -> list[list[float]]:
        try:
            inputs = resources.tokenizer(
                texts,
                padding=True,
                truncation=True,
                return_tensors="pt",
            )

            inputs = {
                key: value.to(self._device)
                for key, value in inputs.items()
            }

            with resources.torch.inference_mode():
                outputs = resources.model(**inputs)

                dense_vectors = outputs.last_hidden_state[:, 0]

            return cast(
                list[list[float]],
                dense_vectors.detach().cpu().float().tolist(),
            )
        except Exception as exc:
            raise ApplicationException(
                code="local_embedding_model_failed",
                message="Local embedding model inference failed.",
            ) from exc

    @staticmethod
    def _validate_vectors(
        *,
        vectors: list[list[float]],
        expected_count: int,
    ) -> None:
        if (
            not isinstance(vectors, list)
            or len(vectors) != expected_count
        ):
            raise ApplicationException(
                code="local_embedding_result_invalid",
                message=(
                    "Local embedding result count does not match input count."
                ),
            )

        if any(
            not isinstance(vector, list)
            or len(vector) != LOCAL_EMBEDDING_DIMENSION
            for vector in vectors
        ):
            raise ApplicationException(
                code="local_embedding_result_invalid",
                message=(
                    "Local embedding vector dimension must be "
                    f"{LOCAL_EMBEDDING_DIMENSION}."
                ),
            )

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
