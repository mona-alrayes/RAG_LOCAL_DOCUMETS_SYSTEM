from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.processing.chunks import NormalizedChunk
from app.processing.cloud_embeddings import CloudJinaEmbedder
from app.processing.jina_provider import JinaProviderError


def _vector(value: float) -> list[float]:
    return [value] * 1024


def test_cloud_jina_embedder_preserves_chunk_order_and_batches() -> None:
    calls: list[list[str]] = []

    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            calls.append(list(texts))

            values = {
                "first chunk": 1.0,
                "second chunk": 2.0,
                "third chunk": 3.0,
            }

            return [_vector(values[text]) for text in texts]

    chunks = [
        NormalizedChunk(text="first chunk"),
        NormalizedChunk(text="second chunk"),
        NormalizedChunk(text="third chunk"),
    ]

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        batch_size=2,
        wait_between_batches=0,
        provider=FakeProvider(),
    )

    vectors = embedder.embed(chunks)

    assert calls == [
        ["first chunk", "second chunk"],
        ["third chunk"],
    ]
    assert vectors == [
        _vector(1.0),
        _vector(2.0),
        _vector(3.0),
    ]


def test_cloud_jina_embedder_uses_passage_task_and_fixed_dimension(
    monkeypatch,
) -> None:
    received_kwargs: dict[str, object] = {}

    class FakeProvider:
        def __init__(self, **kwargs) -> None:
            received_kwargs.update(kwargs)

        def embed(self, texts: list[str]) -> list[list[float]]:
            return [_vector(1.0) for _ in texts]

    monkeypatch.setattr(
        "app.processing.cloud_embeddings.JinaEmbeddingProvider",
        FakeProvider,
    )

    CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
    )

    assert received_kwargs["api_key"] == "test-key"
    assert received_kwargs["model"] == "jina-embeddings-v3"
    assert received_kwargs["task"] == "retrieval.passage"
    assert received_kwargs["dimensions"] == 1024


def test_cloud_jina_embedder_rejects_vector_count_mismatch() -> None:
    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            return [_vector(1.0)]

    chunks = [
        NormalizedChunk(text="first chunk"),
        NormalizedChunk(text="second chunk"),
    ]

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        provider=FakeProvider(),
        wait_between_batches=0,
    )

    try:
        embedder.embed(chunks)
    except ApplicationException as exc:
        assert exc.code == "cloud_embedding_result_invalid"
    else:
        raise AssertionError("Expected ApplicationException")


def test_cloud_jina_embedder_rejects_invalid_vector_dimension() -> None:
    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            return [[1.0] * 512 for _ in texts]

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        provider=FakeProvider(),
    )

    chunks = [
        NormalizedChunk(text="first chunk"),
    ]

    try:
        embedder.embed(chunks)
    except ApplicationException as exc:
        assert exc.code == "cloud_embedding_result_invalid"
    else:
        raise AssertionError("Expected ApplicationException")


def test_cloud_jina_embedding_settings(monkeypatch) -> None:
    monkeypatch.setenv("JINAAI_API_KEY", "test-jina-key")
    monkeypatch.setenv("CLOUD_EMBED_MODEL", "jina-embeddings-v3")
    monkeypatch.setenv("EMBED_BATCH_SIZE", "8")
    monkeypatch.setenv("WAIT_BETWEEN_BATCHES", "1.5")
    monkeypatch.setenv("RATE_LIMIT_RETRY_WAIT", "12.5")
    monkeypatch.setenv("MAX_RETRIES", "4")

    settings = Settings()

    assert settings.jinaai_api_key.get_secret_value() == "test-jina-key"
    assert settings.cloud_embed_model == "jina-embeddings-v3"
    assert settings.embed_batch_size == 8
    assert settings.wait_between_batches == 1.5
    assert settings.rate_limit_retry_wait == 12.5
    assert settings.max_retries == 4


def test_cloud_jina_embedding_settings_defaults() -> None:
    settings = Settings(
        jinaai_api_key="",
        cloud_embed_model="jina-embeddings-v3",
        embed_batch_size=6,
        wait_between_batches=3,
        rate_limit_retry_wait=30,
        max_retries=5,
    )

    assert settings.embed_batch_size == 6
    assert settings.wait_between_batches == 3
    assert settings.rate_limit_retry_wait == 30
    assert settings.max_retries == 5


def test_cloud_jina_embedder_empty_input_skips_provider_and_sleep() -> None:
    provider_called = False
    sleep_calls: list[float] = []

    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            nonlocal provider_called
            provider_called = True
            return []

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        provider=FakeProvider(),
        sleeper=sleep_calls.append,
    )

    vectors = embedder.embed([])

    assert vectors == []
    assert provider_called is False
    assert sleep_calls == []


def test_cloud_jina_embedder_does_not_sleep_after_final_batch() -> None:
    sleep_calls: list[float] = []

    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            return [_vector(1.0) for _ in texts]

    chunks = [
        NormalizedChunk(text="first chunk"),
        NormalizedChunk(text="second chunk"),
        NormalizedChunk(text="third chunk"),
        NormalizedChunk(text="fourth chunk"),
    ]

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        batch_size=2,
        wait_between_batches=3,
        provider=FakeProvider(),
        sleeper=sleep_calls.append,
    )

    embedder.embed(chunks)

    assert sleep_calls == [3]


def test_cloud_jina_embedder_retries_retryable_failure() -> None:
    call_count = 0
    sleep_calls: list[float] = []

    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            nonlocal call_count
            call_count += 1

            if call_count == 1:
                raise JinaProviderError(retryable=True)

            return [_vector(1.0) for _ in texts]

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        provider=FakeProvider(),
        rate_limit_retry_wait=30,
        sleeper=sleep_calls.append,
    )

    vectors = embedder.embed(
        [NormalizedChunk(text="first chunk")]
    )

    assert call_count == 2
    assert sleep_calls == [30]
    assert vectors == [_vector(1.0)]


def test_cloud_jina_embedder_recovers_after_temporary_failure() -> None:
    call_count = 0

    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            nonlocal call_count
            call_count += 1

            if call_count <= 2:
                raise JinaProviderError(retryable=True)

            return [_vector(7.0) for _ in texts]

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        provider=FakeProvider(),
        rate_limit_retry_wait=0,
        max_retries=2,
    )

    vectors = embedder.embed(
        [NormalizedChunk(text="first chunk")]
    )

    assert call_count == 3
    assert vectors == [_vector(7.0)]


def test_cloud_jina_embedder_stops_after_max_retries() -> None:
    call_count = 0
    sleep_calls: list[float] = []

    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            nonlocal call_count
            call_count += 1
            raise JinaProviderError(retryable=True)

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        provider=FakeProvider(),
        max_retries=2,
        rate_limit_retry_wait=30,
        sleeper=sleep_calls.append,
    )

    try:
        embedder.embed(
            [NormalizedChunk(text="first chunk")]
        )
    except ApplicationException as exc:
        assert exc.code == "cloud_embedding_provider_failed"
        assert isinstance(exc.__cause__, JinaProviderError)
    else:
        raise AssertionError("Expected ApplicationException")

    assert call_count == 3
    assert sleep_calls == [30, 30]


def test_cloud_jina_embedder_nonretryable_failure_fails_immediately() -> None:
    call_count = 0
    sleep_calls: list[float] = []

    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            nonlocal call_count
            call_count += 1
            raise JinaProviderError(retryable=False)

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        provider=FakeProvider(),
        max_retries=5,
        sleeper=sleep_calls.append,
    )

    try:
        embedder.embed(
            [NormalizedChunk(text="first chunk")]
        )
    except ApplicationException as exc:
        assert exc.code == "cloud_embedding_provider_failed"
    else:
        raise AssertionError("Expected ApplicationException")

    assert call_count == 1
    assert sleep_calls == []


def test_cloud_jina_embedder_does_not_reprocess_completed_batches() -> None:
    calls: list[list[str]] = []
    second_batch_attempts = 0
    sleep_calls: list[float] = []

    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            nonlocal second_batch_attempts

            calls.append(list(texts))

            if texts == ["third chunk"]:
                second_batch_attempts += 1

                if second_batch_attempts == 1:
                    raise JinaProviderError(retryable=True)

            return [_vector(1.0) for _ in texts]

    chunks = [
        NormalizedChunk(text="first chunk"),
        NormalizedChunk(text="second chunk"),
        NormalizedChunk(text="third chunk"),
    ]

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        batch_size=2,
        wait_between_batches=3,
        rate_limit_retry_wait=30,
        provider=FakeProvider(),
        sleeper=sleep_calls.append,
    )

    vectors = embedder.embed(chunks)

    assert calls == [
        ["first chunk", "second chunk"],
        ["third chunk"],
        ["third chunk"],
    ]
    assert sleep_calls == [3, 30]
    assert len(vectors) == 3


def test_cloud_jina_embedder_wraps_unexpected_provider_failure() -> None:
    class FakeProvider:
        def embed(self, texts: list[str]) -> list[list[float]]:
            raise RuntimeError("Unexpected provider failure")

    embedder = CloudJinaEmbedder(
        api_key="test-key",
        model="jina-embeddings-v3",
        provider=FakeProvider(),
    )

    try:
        embedder.embed(
            [NormalizedChunk(text="first chunk")]
        )
    except ApplicationException as exc:
        assert exc.code == "cloud_embedding_provider_failed"
        assert isinstance(exc.__cause__, RuntimeError)
    else:
        raise AssertionError("Expected ApplicationException")
