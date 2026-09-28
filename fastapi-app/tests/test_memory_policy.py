import pytest
from app.core.exceptions import ApplicationException
from app.runtime.models import ResourceSnapshot


def test_policy_evicts_idle_ollama_before_rejecting_next_stage():
    from app.runtime.memory_policy import LocalMemoryPolicy

    free = [3_000]

    class Cache:
        def resident(self):
            return True

        def unload(self):
            free[0] += 3_000

    policy = LocalMemoryPolicy(
        reserve_bytes=2_000, stage_bytes={"bge": 3_000}, cache=Cache(), settle_seconds=0
    )
    policy.prepare(
        "bge", lambda: ResourceSnapshot(system_available_memory_bytes=free[0])
    )
    assert free[0] == 6_000


def test_policy_does_not_load_when_reclamation_is_insufficient():
    from app.runtime.memory_policy import LocalMemoryPolicy

    class Cache:
        def resident(self):
            return False

        def unload(self):
            raise AssertionError("not resident")

    policy = LocalMemoryPolicy(
        reserve_bytes=2_000, stage_bytes={"bge": 3_000}, cache=Cache(), settle_seconds=0
    )
    with pytest.raises(ApplicationException) as error:
        policy.prepare(
            "bge", lambda: ResourceSnapshot(system_available_memory_bytes=4_000)
        )
    assert error.value.code == "local_resource_exhausted"


def test_policy_waits_briefly_for_memory_to_recover_after_release():
    from app.runtime.memory_policy import LocalMemoryPolicy

    class Cache:
        def resident(self):
            return False

        def unload(self):
            raise AssertionError("not loaded")

    samples = iter([4_000, 6_000])
    policy = LocalMemoryPolicy(
        reserve_bytes=2_000,
        stage_bytes={"bge": 3_000},
        cache=Cache(),
        settle_seconds=0.2,
    )
    policy.prepare(
        "bge", lambda: ResourceSnapshot(system_available_memory_bytes=next(samples))
    )


def test_failed_generation_requires_cleanup_before_next_stage(monkeypatch):
    from app.runtime.memory_policy import LocalMemoryPolicy, OllamaCache

    cache = OllamaCache(base_url="http://localhost", model="test")
    cache.owned = True
    releases = []

    def fail_release():
        releases.append("failed")
        raise ApplicationException(code="local_model_release_failed", message="failed")

    monkeypatch.setattr(cache, "unload", fail_release)
    policy = LocalMemoryPolicy(
        reserve_bytes=2_000, stage_bytes={"bge": 3_000}, cache=cache
    )
    with pytest.raises(ApplicationException):
        policy.generation_finished(
            failed=True,
            snapshot=lambda: ResourceSnapshot(system_available_memory_bytes=8_000),
        )
    monkeypatch.setattr(cache, "resident", lambda: True)
    with pytest.raises(ApplicationException):
        policy.prepare(
            "bge", lambda: ResourceSnapshot(system_available_memory_bytes=8_000)
        )
    assert releases == ["failed", "failed"]


def test_malformed_ollama_state_fails_closed(monkeypatch):
    import httpx2
    from app.runtime.memory_policy import OllamaCache
    original = httpx2.Client
    transport = httpx2.MockTransport(lambda request: httpx2.Response(200, json={'models': ['invalid']}))
    monkeypatch.setattr(httpx2, 'Client', lambda **kwargs: original(transport=transport, **kwargs))
    cache = OllamaCache(base_url='http://localhost', model='test')
    cache.owned = True
    with pytest.raises(ApplicationException) as error:
        cache.resident()
    assert error.value.code == 'local_resource_telemetry_unavailable'


def test_ollama_unload_requires_acknowledgement(monkeypatch):
    import httpx2
    from app.runtime.memory_policy import OllamaCache
    original = httpx2.Client
    transport = httpx2.MockTransport(lambda request: httpx2.Response(200, json={'done': False}))
    monkeypatch.setattr(httpx2, 'Client', lambda **kwargs: original(transport=transport, **kwargs))
    cache = OllamaCache(base_url='http://localhost', model='test')
    cache.owned = True
    with pytest.raises(ApplicationException):
        cache.unload()
    assert cache.owned is True
