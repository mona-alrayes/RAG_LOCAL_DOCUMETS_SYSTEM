from threading import Event, Thread
from time import monotonic, sleep
from weakref import ref

from app.core.exceptions import ApplicationException
from app.runtime.model_coordinator import LocalModelCoordinator
from app.runtime.models import (
    LocalRuntimeSnapshot,
    ResourceSnapshot,
    RuntimeBackend,
    RuntimeDtype,
    RuntimeProbeStatus,
)


class FakeModelResource:
    pass


def test_preflight_uses_stage_memory_policy_without_loading_model():
    class RejectingPolicy:
        def prepare(self, model_id, snapshot):
            assert model_id == "BAAI/bge-m3"
            assert snapshot().system_available_memory_bytes == 8_000
            raise ApplicationException(code="local_resource_exhausted", message="Low memory")

    coordinator = LocalModelCoordinator(
        runtime_snapshot=make_runtime_snapshot(),
        telemetry=FakeTelemetry(),
        runtime=FakeRuntime(),
        min_available_memory_ratio=0.15,
        memory_policy=RejectingPolicy(),
    )
    import pytest

    with pytest.raises(ApplicationException) as error:
        coordinator.preflight("BAAI/bge-m3")
    assert error.value.code == "local_resource_exhausted"
    assert coordinator.active_model is None
    assert coordinator.last_metrics is None


class FakeTelemetry:
    def __init__(
        self,
        *,
        available: int | None = 8_000,
        total: int | None = 10_000,
    ) -> None:
        self.available = available
        self.total = total
        self.calls = 0

    def snapshot(self) -> ResourceSnapshot:
        self.calls += 1

        return ResourceSnapshot(
            process_rss_bytes=1_000,
            system_available_memory_bytes=self.available,
            system_total_memory_bytes=self.total,
        )


class FakeRuntime:
    def __init__(self) -> None:
        self.release_calls: list[RuntimeBackend] = []

    def accelerator_memory(
        self,
        backend: RuntimeBackend,
    ) -> tuple[int | None, int | None]:
        return 200, 300

    def release_cache(self, backend: RuntimeBackend) -> None:
        self.release_calls.append(backend)


def make_runtime_snapshot() -> LocalRuntimeSnapshot:
    return LocalRuntimeSnapshot(
        ready=True,
        requested_device="auto",
        selected_backend=RuntimeBackend.CPU,
        selected_dtype=RuntimeDtype.FP32,
        probe_status=RuntimeProbeStatus.PASSED,
        failure_reason=None,
        resources=ResourceSnapshot(),
    )


def make_coordinator(
    *,
    telemetry: FakeTelemetry | None = None,
    runtime: FakeRuntime | None = None,
) -> tuple[
    LocalModelCoordinator,
    FakeTelemetry,
    FakeRuntime,
]:
    telemetry = telemetry or FakeTelemetry()
    runtime = runtime or FakeRuntime()

    coordinator = LocalModelCoordinator(
        runtime_snapshot=make_runtime_snapshot(),
        telemetry=telemetry,
        runtime=runtime,
        min_available_memory_ratio=0.15,
        max_concurrency=1,
    )

    return coordinator, telemetry, runtime


def test_local_model_coordinator_starts_empty_and_loads_lazily() -> None:
    coordinator, _, _ = make_coordinator()
    load_calls = 0

    def loader() -> object:
        nonlocal load_calls
        load_calls += 1
        return object()

    assert coordinator.active_model is None
    assert coordinator.last_metrics is None
    assert load_calls == 0

    with coordinator.lease(
        model_id="BAAI/bge-m3",
        loader=loader,
    ) as lease:
        assert lease.resource is not None
        assert load_calls == 1
        assert coordinator.active_model == "BAAI/bge-m3"

    assert coordinator.active_model is None


def test_local_model_coordinator_releases_after_success() -> None:
    coordinator, telemetry, runtime = make_coordinator()

    with coordinator.lease(
        model_id="BAAI/bge-m3",
        loader=object,
    ):
        assert coordinator.active_model == "BAAI/bge-m3"

    assert coordinator.active_model is None
    assert runtime.release_calls == [RuntimeBackend.CPU]
    assert telemetry.calls == 3


def test_local_model_coordinator_releases_after_stage_exception() -> None:
    coordinator, _, runtime = make_coordinator()

    try:
        with coordinator.lease(
            model_id="BAAI/bge-m3",
            loader=object,
        ):
            assert coordinator.active_model == "BAAI/bge-m3"
            raise RuntimeError("stage failed")
    except RuntimeError as exc:
        assert str(exc) == "stage failed"
    else:
        raise AssertionError("Expected RuntimeError")

    assert coordinator.active_model is None
    assert runtime.release_calls == [RuntimeBackend.CPU]


def test_local_model_coordinator_cleans_up_after_loader_failure() -> None:
    coordinator, _, runtime = make_coordinator()

    def failing_loader() -> object:
        raise RuntimeError("load failed")

    try:
        with coordinator.lease(
            model_id="BAAI/bge-m3",
            loader=failing_loader,
        ):
            raise AssertionError("Lease body must not execute")
    except RuntimeError as exc:
        assert str(exc) == "load failed"
    else:
        raise AssertionError("Expected RuntimeError")

    assert coordinator.active_model is None
    assert runtime.release_calls == [RuntimeBackend.CPU]


def test_local_model_coordinator_memory_gate_blocks_before_load() -> None:
    telemetry = FakeTelemetry(
        available=1_000,
        total=10_000,
    )
    coordinator, _, runtime = make_coordinator(
        telemetry=telemetry,
    )

    loader_called = False

    def loader() -> object:
        nonlocal loader_called
        loader_called = True
        return object()

    try:
        with coordinator.lease(
            model_id="BAAI/bge-m3",
            loader=loader,
        ):
            raise AssertionError("Lease body must not execute")
    except ApplicationException as exc:
        assert exc.code == "local_resource_exhausted"
    else:
        raise AssertionError("Expected ApplicationException")

    assert loader_called is False
    assert coordinator.active_model is None
    assert coordinator.last_metrics is None
    assert runtime.release_calls == []


def test_local_model_coordinator_records_lifecycle_metrics() -> None:
    coordinator, _, _ = make_coordinator()

    with coordinator.lease(
        model_id="BAAI/bge-m3",
        loader=object,
    ):
        pass

    metrics = coordinator.last_metrics

    assert metrics is not None
    assert metrics.model_id == "BAAI/bge-m3"

    assert metrics.resources_before_load.process_rss_bytes == 1_000
    assert (
        metrics.resources_before_load.system_available_memory_bytes
        == 8_000
    )
    assert metrics.resources_before_load.system_total_memory_bytes == 10_000

    assert metrics.resources_after_load.accelerator_allocated_bytes == 200
    assert (
        metrics.resources_after_load.accelerator_cached_or_reserved_bytes
        == 300
    )

    assert metrics.load_duration_ms >= 0
    assert metrics.release_duration_ms >= 0

    assert metrics.resources_after_release.process_rss_bytes == 1_000


def test_local_model_coordinator_never_has_two_active_models() -> None:
    coordinator, _, _ = make_coordinator()

    first_entered = Event()
    allow_first_release = Event()
    second_entered = Event()

    observed_active_models: list[str | None] = []

    def first_stage() -> None:
        with coordinator.lease(
            model_id="model-a",
            loader=object,
        ):
            observed_active_models.append(coordinator.active_model)
            first_entered.set()
            allow_first_release.wait(timeout=2)

    def second_stage() -> None:
        first_entered.wait(timeout=2)

        with coordinator.lease(
            model_id="model-b",
            loader=object,
        ):
            observed_active_models.append(coordinator.active_model)
            second_entered.set()

    first_thread = Thread(target=first_stage)
    second_thread = Thread(target=second_stage)

    first_thread.start()
    second_thread.start()

    assert first_entered.wait(timeout=2)
    assert coordinator.active_model == "model-a"

    # The second lease must still be blocked by the single-active gate.
    assert second_entered.wait(timeout=0.05) is False
    assert coordinator.active_model == "model-a"

    allow_first_release.set()

    first_thread.join(timeout=2)
    second_thread.join(timeout=2)

    assert first_thread.is_alive() is False
    assert second_thread.is_alive() is False

    assert observed_active_models == [
        "model-a",
        "model-b",
    ]
    assert coordinator.active_model is None


def test_local_model_coordinator_preserves_stage_exception_when_cleanup_fails(
    monkeypatch,
) -> None:
    coordinator, _, runtime = make_coordinator()

    def failing_release_cache(backend: RuntimeBackend) -> None:
        raise RuntimeError("cleanup failed")

    monkeypatch.setattr(
        runtime,
        "release_cache",
        failing_release_cache,
    )

    try:
        with coordinator.lease(
            model_id="BAAI/bge-m3",
            loader=object,
        ):
            raise ValueError("stage failed")
    except ValueError as exc:
        assert str(exc) == "stage failed"
    else:
        raise AssertionError("Expected ValueError")

    assert coordinator.active_model is None


def test_local_model_coordinator_surfaces_cleanup_failure_after_success(
    monkeypatch,
) -> None:
    coordinator, _, runtime = make_coordinator()

    def failing_release_cache(backend: RuntimeBackend) -> None:
        raise RuntimeError("cleanup failed")

    monkeypatch.setattr(
        runtime,
        "release_cache",
        failing_release_cache,
    )

    try:
        with coordinator.lease(
            model_id="BAAI/bge-m3",
            loader=object,
        ):
            pass
    except RuntimeError as exc:
        assert str(exc) == "cleanup failed"
    else:
        raise AssertionError("Expected RuntimeError")

    assert coordinator.active_model is None


def test_local_model_coordinator_released_lease_drops_resource() -> None:
    coordinator, _, _ = make_coordinator()
    resource_reference = None

    def loader() -> FakeModelResource:
        nonlocal resource_reference

        resource = FakeModelResource()
        resource_reference = ref(resource)

        return resource

    with coordinator.lease(
        model_id="BAAI/bge-m3",
        loader=loader,
    ) as lease:
        assert resource_reference is not None
        assert resource_reference() is lease.resource

    try:
        _ = lease.resource
    except RuntimeError as exc:
        assert str(exc) == "Local model lease has already been released."
    else:
        raise AssertionError("Expected RuntimeError")

    assert resource_reference is not None
    assert resource_reference() is None


def test_local_model_coordinator_repeated_leases_release_each_resource() -> None:
    coordinator, _, runtime = make_coordinator()
    resource_references = []

    def loader() -> FakeModelResource:
        resource = FakeModelResource()
        resource_references.append(ref(resource))

        return resource

    for model_id in (
        "BAAI/bge-m3",
        "BAAI/bge-reranker-v2-m3",
    ):
        with coordinator.lease(
            model_id=model_id,
            loader=loader,
        ) as lease:
            assert resource_references[-1]() is lease.resource
            assert coordinator.active_model == model_id

        assert coordinator.active_model is None
        assert resource_references[-1]() is None

    assert all(
        resource_reference() is None
        for resource_reference in resource_references
    )
    assert runtime.release_calls == [
        RuntimeBackend.CPU,
        RuntimeBackend.CPU,
    ]


def test_local_model_coordinator_rechecks_memory_before_each_load() -> None:
    telemetry = FakeTelemetry()
    coordinator, _, runtime = make_coordinator(
        telemetry=telemetry,
    )

    with coordinator.lease(
        model_id="BAAI/bge-m3",
        loader=object,
    ):
        pass

    telemetry.available = 1_000
    second_loader_called = False

    def second_loader() -> object:
        nonlocal second_loader_called
        second_loader_called = True
        return object()

    try:
        with coordinator.lease(
            model_id="BAAI/bge-reranker-v2-m3",
            loader=second_loader,
        ):
            raise AssertionError("Lease body must not execute")
    except ApplicationException as exc:
        assert exc.code == "local_resource_exhausted"
    else:
        raise AssertionError("Expected ApplicationException")

    assert second_loader_called is False
    assert coordinator.active_model is None
    assert runtime.release_calls == [RuntimeBackend.CPU]


def test_local_model_coordinator_fails_closed_without_memory_telemetry() -> None:
    unavailable_snapshots = (
        (None, 10_000),
        (8_000, None),
        (8_000, 0),
    )

    for available, total in unavailable_snapshots:
        telemetry = FakeTelemetry(
            available=available,
            total=total,
        )
        coordinator, _, runtime = make_coordinator(
            telemetry=telemetry,
        )
        loader_called = False

        def loader() -> object:
            nonlocal loader_called
            loader_called = True
            return object()

        try:
            with coordinator.lease(
                model_id="BAAI/bge-m3",
                loader=loader,
            ):
                raise AssertionError("Lease body must not execute")
        except ApplicationException as exc:
            assert exc.code == "local_resource_telemetry_unavailable"
        else:
            raise AssertionError("Expected ApplicationException")

        assert loader_called is False
        assert coordinator.active_model is None
        assert coordinator.last_metrics is None
        assert runtime.release_calls == []


def test_local_model_coordinator_reuses_warm_model_before_expiry() -> None:
    coordinator, _, runtime = make_coordinator()
    load_calls = 0

    def loader() -> FakeModelResource:
        nonlocal load_calls
        load_calls += 1

        return FakeModelResource()

    with coordinator.lease(
        model_id="BAAI/bge-m3",
        loader=loader,
        keep_alive_seconds=60,
    ):
        pass

    assert load_calls == 1
    assert runtime.release_calls == []

    # The second lease must reuse the warm resource rather than reload it.
    # Passing zero here also gives the test deterministic cleanup.
    with coordinator.lease(
        model_id="BAAI/bge-m3",
        loader=loader,
        keep_alive_seconds=0,
    ):
        pass

    assert load_calls == 1
    assert runtime.release_calls == [RuntimeBackend.CPU]


def test_local_model_coordinator_releases_warm_model_after_expiry() -> None:
    coordinator, _, runtime = make_coordinator()

    with coordinator.lease(
        model_id="BAAI/bge-m3",
        loader=FakeModelResource,
        keep_alive_seconds=0.05,
    ):
        pass

    assert runtime.release_calls == []

    deadline = monotonic() + 1.0

    while (
        not runtime.release_calls
        and monotonic() < deadline
    ):
        sleep(0.01)

    assert runtime.release_calls == [RuntimeBackend.CPU]


def test_local_model_coordinator_memory_gate_overrides_keep_alive() -> None:
    telemetry = FakeTelemetry(
        available=8_000,
        total=10_000,
    )
    coordinator, _, runtime = make_coordinator(
        telemetry=telemetry,
    )

    with coordinator.lease(
        model_id="BAAI/bge-m3",
        loader=FakeModelResource,
        keep_alive_seconds=60,
    ):
        pass

    assert runtime.release_calls == []

    telemetry.available = 1_000

    loader_called = False

    def second_loader() -> FakeModelResource:
        nonlocal loader_called
        loader_called = True

        return FakeModelResource()

    try:
        with coordinator.lease(
            model_id="BAAI/bge-reranker-v2-m3",
            loader=second_loader,
            keep_alive_seconds=60,
        ):
            raise AssertionError("Lease body must not execute")
    except ApplicationException as exc:
        assert exc.code == "local_resource_exhausted"
    else:
        raise AssertionError("Expected ApplicationException")

    assert loader_called is False
    assert runtime.release_calls == [RuntimeBackend.CPU]


def test_resource_is_destroyed_before_accelerator_cache_release():
    references = []
    class CheckingRuntime(FakeRuntime):
        def release_cache(self, backend):
            assert references[0]() is None
            super().release_cache(backend)
    def loader():
        resource = FakeModelResource()
        references.append(ref(resource))
        return resource
    coordinator, _, _ = make_coordinator(runtime=CheckingRuntime())
    with coordinator.lease(model_id='test', loader=loader):
        pass


def test_ollama_lease_evicts_warm_torch_and_blocks_other_local_work():
    import asyncio
    references = []
    coordinator, _, _ = make_coordinator()
    def loader():
        model = FakeModelResource()
        references.append(ref(model))
        return model
    with coordinator.lease(model_id='bge', loader=loader, keep_alive_seconds=60):
        pass
    async def run():
        async with coordinator.generation_lease():
            assert references[0]() is None
            assert coordinator.active_model == 'ollama'
            assert not coordinator._gate.acquire(blocking=False)
        assert coordinator.active_model is None
        with coordinator.lease(model_id='bge', loader=loader):
            pass
    asyncio.run(run())


def test_cancelled_generation_releases_exclusive_gate():
    import asyncio
    coordinator, _, _ = make_coordinator()
    async def run():
        try:
            async with coordinator.generation_lease():
                raise asyncio.CancelledError()
        except asyncio.CancelledError:
            pass
        with coordinator.lease(model_id='next', loader=object):
            pass
    asyncio.run(run())


def test_shutdown_releases_warm_models():
    coordinator, _, _ = make_coordinator()
    refs = []
    def loader():
        model = FakeModelResource()
        refs.append(ref(model))
        return model
    with coordinator.lease(model_id='bge', loader=loader, keep_alive_seconds=60):
        pass
    assert refs[0]() is not None
    coordinator.shutdown()
    assert refs[0]() is None
