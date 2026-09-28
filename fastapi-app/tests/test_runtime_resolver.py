from app.core.config import LocalDevice
from app.runtime.models import (
    ResourceSnapshot,
    RuntimeBackend,
    RuntimeDtype,
    RuntimeProbeStatus,
)
from app.runtime.resolver import LocalRuntimeResolver


class FakeRuntime:
    def __init__(
        self,
        available_backends: set[RuntimeBackend],
    ) -> None:
        self.available_backends = available_backends
        self.checked: list[RuntimeBackend] = []
        self.probed: list[tuple[RuntimeBackend, RuntimeDtype]] = []

    def is_available(self, backend: RuntimeBackend) -> bool:
        self.checked.append(backend)
        return backend in self.available_backends

    def probe(
        self,
        backend: RuntimeBackend,
        dtype: RuntimeDtype,
    ) -> None:
        self.probed.append((backend, dtype))

    def accelerator_memory(
        self,
        backend: RuntimeBackend,
    ) -> tuple[int | None, int | None]:
        if backend is RuntimeBackend.CPU:
            return None, None

        return 300, 400


class FakeTelemetry:
    def snapshot(self) -> ResourceSnapshot:
        return ResourceSnapshot(
            process_rss_bytes=100,
            system_available_memory_bytes=200,
        )


def test_auto_selects_first_available_backend() -> None:
    runtime = FakeRuntime(
        {
            RuntimeBackend.MPS,
            RuntimeBackend.CPU,
        }
    )

    resolver = LocalRuntimeResolver(
        runtime=runtime,
        telemetry=FakeTelemetry(),
    )

    snapshot = resolver.resolve(LocalDevice.AUTO)

    assert snapshot.ready is True
    assert snapshot.selected_backend is RuntimeBackend.MPS
    assert snapshot.selected_dtype is RuntimeDtype.FP16
    assert snapshot.probe_status is RuntimeProbeStatus.PASSED

    assert snapshot.resources.process_rss_bytes == 100
    assert snapshot.resources.system_available_memory_bytes == 200
    assert snapshot.resources.accelerator_allocated_bytes == 300
    assert snapshot.resources.accelerator_cached_or_reserved_bytes == 400

    assert runtime.checked == [
        RuntimeBackend.CUDA,
        RuntimeBackend.ROCM,
        RuntimeBackend.XPU,
        RuntimeBackend.MPS,
    ]

    assert runtime.probed == [
        (
            RuntimeBackend.MPS,
            RuntimeDtype.FP16,
        )
    ]


def test_explicit_backend_failure_does_not_fallback() -> None:
    runtime = FakeRuntime(
        {
            RuntimeBackend.CPU,
        }
    )

    resolver = LocalRuntimeResolver(
        runtime=runtime,
        telemetry=FakeTelemetry(),
    )

    snapshot = resolver.resolve(LocalDevice.XPU)

    assert snapshot.ready is False
    assert snapshot.requested_device == "xpu"
    assert snapshot.selected_backend is RuntimeBackend.XPU
    assert snapshot.selected_dtype is RuntimeDtype.FP16
    assert snapshot.probe_status is RuntimeProbeStatus.FAILED

    assert runtime.checked == [
        RuntimeBackend.XPU,
    ]
    assert runtime.probed == []
