from app.core.config import LocalDevice
from app.runtime.models import (
    LocalRuntimeSnapshot,
    ResourceSnapshot,
    RuntimeBackend,
    RuntimeDtype,
    RuntimeProbeStatus,
)
from app.runtime.telemetry import ResourceTelemetry
from app.runtime.torch_runtime import TorchRuntimeAdapter


AUTO_BACKEND_ORDER = (
    RuntimeBackend.CUDA,
    RuntimeBackend.ROCM,
    RuntimeBackend.XPU,
    RuntimeBackend.MPS,
    RuntimeBackend.CPU,
)


class LocalRuntimeResolver:
    def __init__(
        self,
        runtime: TorchRuntimeAdapter,
        telemetry: ResourceTelemetry,
    ) -> None:
        self._runtime = runtime
        self._telemetry = telemetry

    def resolve(
        self,
        requested_device: LocalDevice,
    ) -> LocalRuntimeSnapshot:
        if requested_device is LocalDevice.AUTO:
            return self._resolve_auto()

        backend = RuntimeBackend(requested_device.value)

        return self._resolve_explicit(
            requested_device=requested_device,
            backend=backend,
        )

    def _resolve_auto(self) -> LocalRuntimeSnapshot:
        last_failure_reason: str | None = None

        for backend in AUTO_BACKEND_ORDER:
            dtype = self._dtype_for(backend)

            try:
                if not self._runtime.is_available(backend):
                    continue

                self._runtime.probe(backend, dtype)

                return LocalRuntimeSnapshot(
                    ready=True,
                    requested_device=LocalDevice.AUTO.value,
                    selected_backend=backend,
                    selected_dtype=dtype,
                    probe_status=RuntimeProbeStatus.PASSED,
                    failure_reason=None,
                    resources=self._resource_snapshot(backend),
                )
            except RuntimeError as exc:
                last_failure_reason = self._sanitize_failure(exc)

        return LocalRuntimeSnapshot(
            ready=False,
            requested_device=LocalDevice.AUTO.value,
            selected_backend=None,
            selected_dtype=None,
            probe_status=RuntimeProbeStatus.FAILED,
            failure_reason=(
                last_failure_reason
                or "No supported local runtime backend passed the startup probe."
            ),
            resources=self._telemetry.snapshot(),
        )

    def _resolve_explicit(
        self,
        requested_device: LocalDevice,
        backend: RuntimeBackend,
    ) -> LocalRuntimeSnapshot:
        dtype = self._dtype_for(backend)

        try:
            if not self._runtime.is_available(backend):
                raise RuntimeError(
                    f"Requested local backend is unavailable: {backend.value}"
                )

            self._runtime.probe(backend, dtype)
        except RuntimeError as exc:
            return LocalRuntimeSnapshot(
                ready=False,
                requested_device=requested_device.value,
                selected_backend=backend,
                selected_dtype=dtype,
                probe_status=RuntimeProbeStatus.FAILED,
                failure_reason=self._sanitize_failure(exc),
                resources=self._telemetry.snapshot(),
            )

        return LocalRuntimeSnapshot(
            ready=True,
            requested_device=requested_device.value,
            selected_backend=backend,
            selected_dtype=dtype,
            probe_status=RuntimeProbeStatus.PASSED,
            failure_reason=None,
            resources=self._resource_snapshot(backend),
        )

    def _resource_snapshot(
        self,
        backend: RuntimeBackend,
    ) -> ResourceSnapshot:
        system_resources = self._telemetry.snapshot()

        try:
            allocated, cached_or_reserved = (
                self._runtime.accelerator_memory(backend)
            )
        except RuntimeError:
            allocated = None
            cached_or_reserved = None

        return ResourceSnapshot(
            process_rss_bytes=system_resources.process_rss_bytes,
            system_available_memory_bytes=(
                system_resources.system_available_memory_bytes
            ),
            system_total_memory_bytes=(
                system_resources.system_total_memory_bytes
            ),
            accelerator_allocated_bytes=allocated,
            accelerator_cached_or_reserved_bytes=cached_or_reserved,
        )

    def _dtype_for(
        self,
        backend: RuntimeBackend,
    ) -> RuntimeDtype:
        if backend is RuntimeBackend.CPU:
            return RuntimeDtype.FP32

        return RuntimeDtype.FP16

    def _sanitize_failure(self, error: Exception) -> str:
        message = str(error).strip()

        safe_prefixes = (
            "Local PyTorch runtime is not installed.",
            "Requested local backend is unavailable:",
            "Runtime probe returned an invalid result for backend:",
        )

        if any(
            message.startswith(prefix)
            for prefix in safe_prefixes
        ):
            return message[:500]

        return "Local runtime startup probe failed."
