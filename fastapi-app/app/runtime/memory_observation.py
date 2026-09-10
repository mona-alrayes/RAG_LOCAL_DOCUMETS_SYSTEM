"""Sample process/system memory without logging document or question content."""

import json
import logging
from dataclasses import asdict
from threading import Event, Thread
from time import perf_counter

from app.runtime.telemetry import ResourceTelemetry


class MemoryObservation:
    def __init__(self, stage: str, telemetry: ResourceTelemetry):
        self.stage = stage
        self.telemetry = telemetry
        self.stop = Event()
        self.minimum_available = None
        self.maximum_rss = None
        self.samples = 0
        self.started = perf_counter()
        self.before = self._sample()
        self.thread = Thread(target=self._run, daemon=True)
        self.thread.start()

    def _sample(self):
        try:
            snapshot = self.telemetry.snapshot()
            available = snapshot.system_available_memory_bytes
            rss = snapshot.process_rss_bytes
            if available is not None:
                self.minimum_available = (
                    available
                    if self.minimum_available is None
                    else min(available, self.minimum_available)
                )
            if rss is not None:
                self.maximum_rss = (
                    rss if self.maximum_rss is None else max(rss, self.maximum_rss)
                )
            self.samples += 1
            return asdict(snapshot)
        except Exception:  # noqa: BLE001 - optional sampling must not interrupt model cleanup.
            return None

    def _run(self):
        while not self.stop.wait(0.25):
            self._sample()

    def finish(self):
        self.stop.set()
        self.thread.join(timeout=1)
        after = self._sample()
        logging.getLogger("uvicorn.error.memory").info(
            "memory_observation %s",
            json.dumps(
                {
                    "stage": self.stage,
                    "duration_ms": round((perf_counter() - self.started) * 1000),
                    "before": self.before,
                    "after": after,
                    "sampled_minimum_available_bytes": self.minimum_available,
                    "sampled_maximum_process_rss_bytes": self.maximum_rss,
                    "samples": self.samples,
                }
            ),
        )
