"""Stage headroom and reclaimable Ollama cache, used under the coordinator gate."""

import logging
from collections.abc import Callable
from time import monotonic, sleep
from typing import Protocol

import httpx2

from app.core.exceptions import ApplicationException
from app.runtime.models import ResourceSnapshot

logger = logging.getLogger("uvicorn.error.memory")


class ModelCache(Protocol):
    def resident(self) -> bool: ...
    def unload(self) -> None: ...


class OllamaCache:
    def __init__(self, *, base_url: str, model: str):
        self.base_url = base_url
        self.model = model
        # Never evict a model started by an unrelated client before this process.
        self.owned = False

    def resident(self) -> bool:
        if not self.owned:
            return False
        try:
            with httpx2.Client(
                base_url=self.base_url, timeout=5, trust_env=False
            ) as client:
                response = client.get("/api/ps")
                response.raise_for_status()
                payload = response.json()
                if not isinstance(payload, dict):
                    raise TypeError("Invalid model state")
                models = payload.get("models")
                if not isinstance(models, list) or any(
                    not isinstance(model, dict) for model in models
                ):
                    raise ValueError("Invalid model list")
                return any(
                    m.get("name") == self.model or m.get("model") == self.model
                    for m in models
                )
        except (httpx2.HTTPError, ValueError, KeyError, TypeError):
            raise ApplicationException(
                code="local_resource_telemetry_unavailable",
                message="Ollama memory state is unavailable.",
            ) from None

    def unload(self) -> None:
        if not self.owned:
            return
        try:
            with httpx2.Client(
                base_url=self.base_url, timeout=15, trust_env=False
            ) as client:
                response = client.post(
                    "/api/generate",
                    json={
                        "model": self.model,
                        "keep_alive": 0,
                        "stream": False,
                    },
                )
                response.raise_for_status()
                payload = response.json()
                if not isinstance(payload, dict) or payload.get("done") is not True:
                    raise ValueError("Unload not acknowledged")
            self.owned = False
            logger.info("ollama_cache_evicted model=%s", self.model)
        except (httpx2.HTTPError, ValueError):
            raise ApplicationException(
                code="local_model_release_failed",
                message="Ollama model could not be released.",
            ) from None


class LocalMemoryPolicy:
    def __init__(
        self,
        *,
        reserve_bytes: int,
        stage_bytes: dict[str, int],
        cache: ModelCache,
        settle_seconds: float = 3.0,
    ):
        self.settle_seconds = settle_seconds
        self.cleanup_required = False
        self.reserve_bytes = reserve_bytes
        self.stage_bytes = stage_bytes
        self.cache = cache

    def prepare(self, stage: str, snapshot: Callable[[], ResourceSnapshot]) -> None:
        if self.cleanup_required:
            self.cache.unload()
            self.cleanup_required = False
        resident = self.cache.resident()
        # A warm generator still needs working space, but not another copy of weights.
        estimate = (
            min(self.stage_bytes.get(stage, 0), 1024**3)
            if stage == "ollama" and resident
            else self.stage_bytes.get(stage, 0)
        )
        required = self.reserve_bytes + estimate
        available = snapshot().system_available_memory_bytes
        if available is not None and available < required and resident:
            self.cache.unload()
            available = snapshot().system_available_memory_bytes
            required = self.reserve_bytes + self.stage_bytes.get(stage, 0)
        deadline = monotonic() + self.settle_seconds
        while available is not None and available < required and monotonic() < deadline:
            sleep(min(0.1, max(0, deadline - monotonic())))
            available = snapshot().system_available_memory_bytes
        logger.info(
            "headroom stage=%s available_bytes=%s required_bytes=%s",
            stage,
            available,
            required,
        )
        if available is None:
            raise ApplicationException(
                code="local_resource_telemetry_unavailable",
                message="Local memory telemetry is unavailable.",
            )
        if available < required:
            raise ApplicationException(
                code="local_resource_exhausted",
                message="Insufficient memory for the next local inference stage.",
            )

    def generation_started(self):
        if isinstance(self.cache, OllamaCache):
            self.cache.owned = True

    def generation_finished(
        self, *, failed: bool, snapshot: Callable[[], ResourceSnapshot]
    ):
        available = snapshot().system_available_memory_bytes
        if failed or available is None or available < self.reserve_bytes:
            self.cleanup_required = True
            self.cache.unload()
            self.cleanup_required = False
