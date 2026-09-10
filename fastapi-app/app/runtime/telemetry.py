from typing import Protocol

from app.runtime.models import ResourceSnapshot


class ResourceTelemetry(Protocol):
    def snapshot(self) -> ResourceSnapshot:
        ...


class PsutilResourceTelemetry:
    def snapshot(self) -> ResourceSnapshot:
        try:
            import psutil
        except ModuleNotFoundError:
            return ResourceSnapshot()

        process = psutil.Process()
        system_memory = psutil.virtual_memory()

        return ResourceSnapshot(
            process_rss_bytes=process.memory_info().rss,
            system_available_memory_bytes=system_memory.available,
            system_total_memory_bytes=system_memory.total,
        )

