from dataclasses import dataclass
from enum import StrEnum


class RuntimeBackend(StrEnum):
    CUDA = "cuda"
    ROCM = "rocm"
    XPU = "xpu"
    MPS = "mps"
    CPU = "cpu"


class RuntimeDtype(StrEnum):
    FP16 = "fp16"
    FP32 = "fp32"


class RuntimeProbeStatus(StrEnum):
    PASSED = "passed"
    FAILED = "failed"


@dataclass(frozen=True, slots=True)
class ResourceSnapshot:
    process_rss_bytes: int | None = None
    system_available_memory_bytes: int | None = None
    system_total_memory_bytes: int | None = None
    accelerator_allocated_bytes: int | None = None
    accelerator_cached_or_reserved_bytes: int | None = None


@dataclass(frozen=True, slots=True)
class LocalRuntimeSnapshot:
    ready: bool
    requested_device: str
    selected_backend: RuntimeBackend | None
    selected_dtype: RuntimeDtype | None
    probe_status: RuntimeProbeStatus
    failure_reason: str | None
    resources: ResourceSnapshot
