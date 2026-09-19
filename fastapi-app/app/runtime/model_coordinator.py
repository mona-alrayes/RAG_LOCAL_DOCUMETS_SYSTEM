import asyncio
import gc
import json
import logging
from collections import OrderedDict
from collections.abc import Callable, Iterator
from contextlib import asynccontextmanager, contextmanager
from dataclasses import asdict, dataclass
from sys import exc_info
from threading import BoundedSemaphore, Lock, Timer
from time import monotonic, perf_counter
from typing import Generic, TypeVar, cast

from app.core.exceptions import ApplicationException
from app.runtime.memory_observation import MemoryObservation
from app.runtime.memory_policy import LocalMemoryPolicy
from app.runtime.models import (
    LocalRuntimeSnapshot,
    ResourceSnapshot,
)
from app.runtime.telemetry import ResourceTelemetry
from app.runtime.torch_runtime import TorchRuntimeAdapter

ModelT = TypeVar("ModelT")


class LocalModelLease(Generic[ModelT]):
    def __init__(self, resource: ModelT) -> None:
        self._resource: ModelT | None = resource

    @property
    def resource(self) -> ModelT:
        if self._resource is None:
            raise RuntimeError(
                "Local model lease has already been released."
            )

        return self._resource

    def _release(self) -> None:
        self._resource = None


@dataclass(frozen=True, slots=True)
class LocalModelLifecycleMetrics:
    model_id: str
    resources_before_load: ResourceSnapshot
    load_duration_ms: int
    resources_after_load: ResourceSnapshot
    release_duration_ms: int
    resources_after_release: ResourceSnapshot


@dataclass(slots=True)
class _WarmModelResource:
    resource: object | None
    expires_at: float
    timer: Timer


class LocalModelCoordinator:
    _MAX_WARM_MODELS = 2

    def __init__(
        self,
        *,
        runtime_snapshot: LocalRuntimeSnapshot,
        telemetry: ResourceTelemetry,
        runtime: TorchRuntimeAdapter,
        min_available_memory_ratio: float,
        max_concurrency: int = 1,
        memory_policy: LocalMemoryPolicy | None = None,
    ) -> None:
        if max_concurrency != 1:
            raise ValueError(
                "LocalModelCoordinator supports max_concurrency=1 only."
            )

        if (
            not runtime_snapshot.ready
            or runtime_snapshot.selected_backend is None
        ):
            raise ApplicationException(
                code="local_model_runtime_unavailable",
                message="Local model runtime is not ready.",
            )

        self._memory_policy = memory_policy
        self._backend = runtime_snapshot.selected_backend
        self._telemetry = telemetry
        self._runtime = runtime
        self._min_available_memory_ratio = min_available_memory_ratio

        self._gate = BoundedSemaphore(max_concurrency)
        self._state_lock = Lock()

        self._active_model: str | None = None
        self._last_metrics: LocalModelLifecycleMetrics | None = None

        self._warm_resources: OrderedDict[
            str,
            _WarmModelResource,
        ] = OrderedDict()

    @property
    def active_model(self) -> str | None:
        with self._state_lock:
            return self._active_model

    @property
    def last_metrics(self) -> LocalModelLifecycleMetrics | None:
        with self._state_lock:
            return self._last_metrics

    def preflight(self, model_id: str) -> None:
        """Check admission without loading weights; lease rechecks after parsing."""
        with self._gate:
            if self._memory_policy is not None:
                for cached_id in list(self._warm_resources):
                    if cached_id != model_id:
                        self._release_warm_resource(cached_id)
                self._memory_policy.prepare(model_id, self._resource_snapshot)
            self._release_warm_models_until_memory_available()
            self._ensure_memory_available(self._resource_snapshot())

    @contextmanager
    def lease(
        self,
        *,
        model_id: str,
        loader: Callable[[], ModelT],
        keep_alive_seconds: float = 0.0,
    ) -> Iterator[LocalModelLease[ModelT]]:
        if keep_alive_seconds < 0:
            raise ValueError(
                "keep_alive_seconds must not be negative."
            )

        with self._gate:
            if self._memory_policy is not None:
                for cached_id in list(self._warm_resources):
                    if cached_id != model_id:
                        self._release_warm_resource(cached_id)
                self._memory_policy.prepare(model_id, self._resource_snapshot)
            self._release_warm_models_until_memory_available()

            resources_before_load = self._resource_snapshot()
            self._ensure_memory_available(resources_before_load)

            observation = MemoryObservation(model_id, self._telemetry) if self._memory_policy else None
            load_started = perf_counter()
            resource = self._take_warm_resource(model_id)
            cache_hit = resource is not None

            lease: LocalModelLease[ModelT] | None = None
            resources_after_load: ResourceSnapshot | None = None

            try:
                if resource is None:
                    resource = loader()

                lease = LocalModelLease(cast(ModelT, resource))

                load_duration_ms = (
                    0
                    if cache_hit
                    else self._elapsed_ms(load_started)
                )

                resources_after_load = self._resource_snapshot()

                with self._state_lock:
                    self._active_model = model_id

                yield lease
            finally:
                if observation is not None:
                    observation.finish()
                release_started = perf_counter()
                had_active_exception = exc_info()[0] is not None

                with self._state_lock:
                    self._active_model = None

                should_keep_warm = (
                    keep_alive_seconds > 0
                    and not had_active_exception
                    and resource is not None
                )

                if should_keep_warm:
                    self._store_warm_resource(
                        model_id=model_id,
                        resource=resource,
                        keep_alive_seconds=keep_alive_seconds,
                    )

                if lease is not None:
                    lease._release()

                resource = None

                if not should_keep_warm:
                    gc.collect()

                    try:
                        self._runtime.release_cache(self._backend)
                    except Exception:
                        if not had_active_exception:
                            raise

                self._release_warm_models_until_memory_available()

                release_duration_ms = self._elapsed_ms(
                    release_started
                )

                try:
                    resources_after_release = (
                        self._resource_snapshot()
                    )
                except Exception:
                    if not had_active_exception:
                        raise

                    resources_after_release = ResourceSnapshot()

                if resources_after_load is not None:
                    metrics = LocalModelLifecycleMetrics(
                        model_id=model_id,
                        resources_before_load=resources_before_load,
                        load_duration_ms=load_duration_ms,
                        resources_after_load=resources_after_load,
                        release_duration_ms=release_duration_ms,
                        resources_after_release=resources_after_release,
                    )

                    with self._state_lock:
                        self._last_metrics = metrics
                    logging.getLogger('uvicorn.error.memory').info(
                        'model_lifecycle %s', json.dumps(asdict(metrics)))

    @asynccontextmanager
    async def generation_lease(self):
        # Polling a nonblocking acquire avoids orphaned threadpool acquisitions
        # if an awaiting request is cancelled.
        while not self._gate.acquire(blocking=False):
            await asyncio.sleep(0.05)
        failed = True
        started = False
        observation = None
        try:
            for model_id in list(self._warm_resources):
                self._release_warm_resource(model_id)
            if self._memory_policy is not None:
                preparation = asyncio.create_task(asyncio.to_thread(
                    self._memory_policy.prepare, 'ollama', self._resource_snapshot))
                try:
                    await asyncio.shield(preparation)
                except asyncio.CancelledError:
                    await preparation
                    raise
                self._memory_policy.generation_started()
            self._ensure_memory_available(self._resource_snapshot())
            with self._state_lock:
                self._active_model = 'ollama'
            started = True
            observation = MemoryObservation("ollama", self._telemetry)
            yield
            failed = False
        finally:
            try:
                if started and self._memory_policy is not None:
                    # Keep the gate held until cancellation/error cleanup finishes.
                    self._memory_policy.generation_finished(
                        failed=failed, snapshot=self._resource_snapshot)
            finally:
                if observation is not None:
                    observation.finish()
                with self._state_lock:
                    self._active_model = None
                self._gate.release()

    def shutdown(self) -> None:
        with self._gate:
            for model_id in list(self._warm_resources):
                self._release_warm_resource(model_id)
            if self._memory_policy is not None:
                self._memory_policy.cache.unload()

    def _take_warm_resource(
        self,
        model_id: str,
    ) -> object | None:
        cached = self._warm_resources.pop(
            model_id,
            None,
        )

        if cached is None:
            return None

        cached.timer.cancel()

        resource = cached.resource
        cached.resource = None

        return resource

    def _store_warm_resource(
        self,
        *,
        model_id: str,
        resource: object,
        keep_alive_seconds: float,
    ) -> None:
        existing = self._warm_resources.pop(
            model_id,
            None,
        )

        if existing is not None:
            existing.timer.cancel()
            existing.resource = None

        while (
            len(self._warm_resources)
            >= self._MAX_WARM_MODELS
        ):
            oldest_model_id = next(
                iter(self._warm_resources)
            )
            self._release_warm_resource(
                oldest_model_id
            )

        expires_at = (
            monotonic()
            + keep_alive_seconds
        )

        timer = Timer(
            keep_alive_seconds,
            self._expire_warm_resource,
            args=(model_id, expires_at),
        )
        timer.daemon = True

        self._warm_resources[model_id] = (
            _WarmModelResource(
                resource=resource,
                expires_at=expires_at,
                timer=timer,
            )
        )

        timer.start()

    def _expire_warm_resource(
        self,
        model_id: str,
        expected_expires_at: float,
    ) -> None:
        with self._gate:
            cached = self._warm_resources.get(
                model_id
            )

            if (
                cached is None
                or cached.expires_at
                != expected_expires_at
                or monotonic()
                < cached.expires_at
            ):
                return

            try:
                self._release_warm_resource(
                    model_id
                )
            except Exception:  # noqa: BLE001 - best-effort background cache expiration.
                # Background expiration must never terminate
                # request processing or the application.
                return

    def _release_warm_resource(
        self,
        model_id: str,
    ) -> None:
        cached = self._warm_resources.pop(
            model_id,
            None,
        )

        if cached is None:
            return

        cached.timer.cancel()
        cached.resource = None

        gc.collect()
        self._runtime.release_cache(
            self._backend
        )

    def _release_warm_models_until_memory_available(
        self,
    ) -> None:
        if not self._warm_resources:
            return

        resources = self._resource_snapshot()

        while (
            self._warm_resources
            and not self._has_required_memory(
                resources
            )
        ):
            oldest_model_id = next(
                iter(self._warm_resources)
            )

            self._release_warm_resource(
                oldest_model_id
            )

            resources = self._resource_snapshot()

    def _has_required_memory(
        self,
        resources: ResourceSnapshot,
    ) -> bool:
        available = (
            resources.system_available_memory_bytes
        )
        total = resources.system_total_memory_bytes

        if (
            available is None
            or total is None
            or total <= 0
        ):
            return False

        return (
            available / total
            >= self._min_available_memory_ratio
        )

    def _ensure_memory_available(
        self,
        resources: ResourceSnapshot,
    ) -> None:
        available = resources.system_available_memory_bytes
        total = resources.system_total_memory_bytes

        if (
            available is None
            or total is None
            or total <= 0
        ):
            raise ApplicationException(
                code="local_resource_telemetry_unavailable",
                message="Local memory telemetry is unavailable.",
            )

        if not self._has_required_memory(
            resources
        ):
            raise ApplicationException(
                code="local_resource_exhausted",
                message=(
                    "Available local memory is below "
                    "the required minimum."
                ),
            )

    def _resource_snapshot(self) -> ResourceSnapshot:
        system_resources = self._telemetry.snapshot()

        try:
            allocated, cached_or_reserved = (
                self._runtime.accelerator_memory(
                    self._backend
                )
            )
        except RuntimeError:
            allocated = None
            cached_or_reserved = None

        return ResourceSnapshot(
            process_rss_bytes=(
                system_resources.process_rss_bytes
            ),
            system_available_memory_bytes=(
                system_resources
                .system_available_memory_bytes
            ),
            system_total_memory_bytes=(
                system_resources
                .system_total_memory_bytes
            ),
            accelerator_allocated_bytes=allocated,
            accelerator_cached_or_reserved_bytes=(
                cached_or_reserved
            ),
        )

    @staticmethod
    def _elapsed_ms(
        started: float,
    ) -> int:
        return round(
            (perf_counter() - started) * 1000
        )
