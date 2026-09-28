from collections.abc import Callable, Iterator
from contextlib import contextmanager
from types import SimpleNamespace
from typing import Any

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.processing.chunks import NormalizedChunk
from app.processing.local_embeddings import LocalBgeM3Embedder
from app.runtime.models import (
    LocalRuntimeSnapshot,
    ResourceSnapshot,
    RuntimeBackend,
    RuntimeDtype,
    RuntimeProbeStatus,
)


class FakeInputTensor:
    def __init__(self) -> None:
        self.device: str | None = None

    def to(self, device: str) -> "FakeInputTensor":
        self.device = device
        return self


class FakeDenseTensor:
    def __init__(self, vectors: list[list[float]]) -> None:
        self._vectors = vectors

    def __getitem__(self, key) -> "FakeDenseTensor":
        assert key == (slice(None), 0)
        return self

    def detach(self) -> "FakeDenseTensor":
        return self

    def cpu(self) -> "FakeDenseTensor":
        return self

    def float(self) -> "FakeDenseTensor":
        return self

    def tolist(self) -> list[list[float]]:
        return self._vectors


class FakeInferenceMode:
    def __enter__(self) -> None:
        return None

    def __exit__(self, exc_type, exc_value, traceback) -> None:
        return None


class FakeLocalModelCoordinator:
    def __init__(self) -> None:
        self.model_ids: list[str] = []

    @contextmanager
    def lease(
        self,
        *,
        model_id: str,
        loader: Callable[[], Any],
        keep_alive_seconds: float = 0.0,
    ) -> Iterator[SimpleNamespace]:
        self.model_ids.append(model_id)
        resource = loader()

        try:
            yield SimpleNamespace(resource=resource)
        finally:
            resource = None


def make_runtime(
    backend: RuntimeBackend = RuntimeBackend.CPU,
    dtype: RuntimeDtype = RuntimeDtype.FP32,
) -> LocalRuntimeSnapshot:
    return LocalRuntimeSnapshot(
        ready=True,
        requested_device="auto",
        selected_backend=backend,
        selected_dtype=dtype,
        probe_status=RuntimeProbeStatus.PASSED,
        failure_reason=None,
        resources=ResourceSnapshot(),
    )


def make_embedder(
    runtime: LocalRuntimeSnapshot | None = None,
) -> LocalBgeM3Embedder:
    return LocalBgeM3Embedder(
        model="BAAI/bge-m3",
        runtime=runtime or make_runtime(),
        coordinator=FakeLocalModelCoordinator(),
    )


def install_fake_local_modules(
    monkeypatch,
    vectors: list[list[float]],
) -> tuple[dict[str, object], list[FakeInputTensor]]:
    received: dict[str, object] = {}
    input_tensors = [FakeInputTensor(), FakeInputTensor()]

    class FakeTokenizer:
        @classmethod
        def from_pretrained(cls, model: str) -> "FakeTokenizer":
            received["tokenizer_model"] = model
            return cls()

        def __call__(self, texts: list[str], **kwargs):
            received["texts"] = texts
            received["tokenizer_kwargs"] = kwargs
            return {
                "input_ids": input_tensors[0],
                "attention_mask": input_tensors[1],
            }

    class FakeModel:
        @classmethod
        def from_pretrained(cls, model: str, **kwargs) -> "FakeModel":
            received["model"] = model
            received["model_kwargs"] = kwargs
            return cls()

        def to(self, device: str) -> "FakeModel":
            received["device"] = device
            return self

        def eval(self) -> None:
            received["eval_called"] = True

        def __call__(self, **inputs):
            received["model_inputs"] = inputs
            return SimpleNamespace(
                last_hidden_state=FakeDenseTensor(vectors),
            )

    fake_torch = SimpleNamespace(
        float16="fake-fp16",
        float32="fake-fp32",
        inference_mode=lambda: FakeInferenceMode(),
    )
    fake_transformers = SimpleNamespace(
        AutoTokenizer=FakeTokenizer,
        AutoModel=FakeModel,
    )

    def fake_import_module(name: str):
        if name == "torch":
            return fake_torch

        if name == "transformers":
            return fake_transformers

        raise AssertionError(f"Unexpected module import: {name}")

    monkeypatch.setattr(
        "app.processing.local_embeddings.import_module",
        fake_import_module,
    )

    return received, input_tensors


def test_local_bge_m3_embedder_preserves_chunk_order_and_dense_values(
    monkeypatch,
) -> None:
    vectors = [
        [3.0] * 1024,
        [7.0] * 1024,
    ]
    received, _ = install_fake_local_modules(monkeypatch, vectors)

    embedder = make_embedder()

    chunks = [
        NormalizedChunk(text="first chunk"),
        NormalizedChunk(text="second chunk"),
    ]

    result = embedder.embed(chunks)

    assert received["texts"] == [
        "first chunk",
        "second chunk",
    ]
    assert result == vectors


def test_local_bge_m3_embedder_uses_runtime_device_and_dtype(
    monkeypatch,
) -> None:
    received, input_tensors = install_fake_local_modules(
        monkeypatch,
        [[1.0] * 1024],
    )

    embedder = make_embedder(
        make_runtime(
            backend=RuntimeBackend.MPS,
            dtype=RuntimeDtype.FP16,
        )
    )

    embedder.embed([NormalizedChunk(text="chunk")])

    assert received["model"] == "BAAI/bge-m3"
    assert received["model_kwargs"]["torch_dtype"] == "fake-fp16"
    assert received["device"] == "mps"
    assert all(tensor.device == "mps" for tensor in input_tensors)


def test_local_bge_m3_embedder_maps_rocm_to_torch_cuda_device(
    monkeypatch,
) -> None:
    received, _ = install_fake_local_modules(
        monkeypatch,
        [[1.0] * 1024],
    )

    embedder = make_embedder(
        make_runtime(
            backend=RuntimeBackend.ROCM,
            dtype=RuntimeDtype.FP16,
        )
    )

    embedder.embed([NormalizedChunk(text="chunk")])

    assert received["device"] == "cuda"


def test_local_bge_m3_embedder_rejects_unavailable_runtime() -> None:
    runtime = LocalRuntimeSnapshot(
        ready=False,
        requested_device="xpu",
        selected_backend=None,
        selected_dtype=None,
        probe_status=RuntimeProbeStatus.FAILED,
        failure_reason="XPU unavailable",
        resources=ResourceSnapshot(),
    )

    try:
        make_embedder(runtime)
    except ApplicationException as exc:
        assert exc.code == "local_embedding_runtime_unavailable"
    else:
        raise AssertionError("Expected ApplicationException")


def test_local_bge_m3_embedder_rejects_vector_count_mismatch(
    monkeypatch,
) -> None:
    install_fake_local_modules(
        monkeypatch,
        [[1.0] * 1024],
    )

    embedder = make_embedder()

    chunks = [
        NormalizedChunk(text="first"),
        NormalizedChunk(text="second"),
    ]

    try:
        embedder.embed(chunks)
    except ApplicationException as exc:
        assert exc.code == "local_embedding_result_invalid"
    else:
        raise AssertionError("Expected ApplicationException")


def test_local_bge_m3_embedder_rejects_invalid_vector_dimension(
    monkeypatch,
) -> None:
    install_fake_local_modules(
        monkeypatch,
        [[1.0] * 512],
    )

    embedder = make_embedder()

    try:
        embedder.embed([NormalizedChunk(text="chunk")])
    except ApplicationException as exc:
        assert exc.code == "local_embedding_result_invalid"
    else:
        raise AssertionError("Expected ApplicationException")


def test_local_bge_m3_embedding_settings(monkeypatch) -> None:
    monkeypatch.setenv("LOCAL_EMBED_MODEL", "/models/bge-m3")

    settings = Settings()

    assert settings.local_embed_model == "/models/bge-m3"


def test_local_bge_m3_embedder_wraps_model_load_failure(
    monkeypatch,
) -> None:
    fake_torch = SimpleNamespace(
        float16="fake-fp16",
        float32="fake-fp32",
    )

    class FailingTokenizer:
        @classmethod
        def from_pretrained(cls, model: str):
            raise RuntimeError("model unavailable")

    fake_transformers = SimpleNamespace(
        AutoTokenizer=FailingTokenizer,
    )

    def fake_import_module(name: str):
        if name == "torch":
            return fake_torch

        if name == "transformers":
            return fake_transformers

        raise AssertionError(f"Unexpected module import: {name}")

    monkeypatch.setattr(
        "app.processing.local_embeddings.import_module",
        fake_import_module,
    )

    embedder = make_embedder()

    try:
        embedder.embed([NormalizedChunk(text="chunk")])
    except ApplicationException as exc:
        assert exc.code == "local_embedding_model_failed"
    else:
        raise AssertionError("Expected ApplicationException")


def test_local_bge_m3_embedder_wraps_inference_failure(
    monkeypatch,
) -> None:
    class FailingModel:
        @classmethod
        def from_pretrained(cls, model: str, **kwargs) -> "FailingModel":
            return cls()

        def to(self, device: str) -> "FailingModel":
            return self

        def eval(self) -> None:
            pass

        def __call__(self, **inputs):
            raise RuntimeError("inference failed")

    class FakeTokenizer:
        @classmethod
        def from_pretrained(cls, model: str) -> "FakeTokenizer":
            return cls()

        def __call__(self, texts: list[str], **kwargs):
            return {"input_ids": FakeInputTensor()}

    fake_torch = SimpleNamespace(
        float16="fake-fp16",
        float32="fake-fp32",
        inference_mode=lambda: FakeInferenceMode(),
    )
    fake_transformers = SimpleNamespace(
        AutoTokenizer=FakeTokenizer,
        AutoModel=FailingModel,
    )

    def fake_import_module(name: str):
        if name == "torch":
            return fake_torch

        if name == "transformers":
            return fake_transformers

        raise AssertionError(f"Unexpected module import: {name}")

    monkeypatch.setattr(
        "app.processing.local_embeddings.import_module",
        fake_import_module,
    )

    embedder = make_embedder()

    try:
        embedder.embed([NormalizedChunk(text="chunk")])
    except ApplicationException as exc:
        assert exc.code == "local_embedding_model_failed"
    else:
        raise AssertionError("Expected ApplicationException")
