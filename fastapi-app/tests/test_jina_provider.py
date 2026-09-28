import json
from urllib.error import HTTPError, URLError

from app.processing.jina_provider import (
    JinaEmbeddingProvider,
    JinaProviderError,
)


def _provider() -> JinaEmbeddingProvider:
    return JinaEmbeddingProvider(
        api_key="test-key",
        model="jina-embeddings-v3",
        task="retrieval.passage",
        dimensions=1024,
    )


def test_jina_provider_marks_rate_limit_as_retryable(monkeypatch) -> None:
    def fake_urlopen(request):
        raise HTTPError(
            url="https://api.jina.ai/v1/embeddings",
            code=429,
            msg="Too Many Requests",
            hdrs=None,
            fp=None,
        )

    monkeypatch.setattr(
        "app.processing.jina_provider.urlopen",
        fake_urlopen,
    )

    try:
        _provider().embed(["first chunk"])
    except JinaProviderError as exc:
        assert exc.retryable is True
    else:
        raise AssertionError("Expected JinaProviderError")


def test_jina_provider_marks_transient_server_failure_as_retryable(
    monkeypatch,
) -> None:
    def fake_urlopen(request):
        raise HTTPError(
            url="https://api.jina.ai/v1/embeddings",
            code=503,
            msg="Service Unavailable",
            hdrs=None,
            fp=None,
        )

    monkeypatch.setattr(
        "app.processing.jina_provider.urlopen",
        fake_urlopen,
    )

    try:
        _provider().embed(["first chunk"])
    except JinaProviderError as exc:
        assert exc.retryable is True
    else:
        raise AssertionError("Expected JinaProviderError")


def test_jina_provider_marks_auth_failure_as_nonretryable(monkeypatch) -> None:
    def fake_urlopen(request):
        raise HTTPError(
            url="https://api.jina.ai/v1/embeddings",
            code=401,
            msg="Unauthorized",
            hdrs=None,
            fp=None,
        )

    monkeypatch.setattr(
        "app.processing.jina_provider.urlopen",
        fake_urlopen,
    )

    try:
        _provider().embed(["first chunk"])
    except JinaProviderError as exc:
        assert exc.retryable is False
    else:
        raise AssertionError("Expected JinaProviderError")


def test_jina_provider_marks_network_failure_as_retryable(monkeypatch) -> None:
    def fake_urlopen(request):
        raise URLError("temporary network failure")

    monkeypatch.setattr(
        "app.processing.jina_provider.urlopen",
        fake_urlopen,
    )

    try:
        _provider().embed(["first chunk"])
    except JinaProviderError as exc:
        assert exc.retryable is True
    else:
        raise AssertionError("Expected JinaProviderError")


def test_jina_provider_preserves_provider_index_order(monkeypatch) -> None:
    body = {
        "data": [
            {
                "index": 1,
                "embedding": [2.0, 2.0],
            },
            {
                "index": 0,
                "embedding": [1.0, 1.0],
            },
        ]
    }

    class FakeResponse:
        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc_value, traceback):
            return False

        def read(self) -> bytes:
            return json.dumps(body).encode("utf-8")

    def fake_urlopen(request):
        return FakeResponse()

    monkeypatch.setattr(
        "app.processing.jina_provider.urlopen",
        fake_urlopen,
    )

    vectors = _provider().embed(
        ["first chunk", "second chunk"]
    )

    assert vectors == [
        [1.0, 1.0],
        [2.0, 2.0],
    ]


def test_jina_provider_rejects_invalid_response_contract(monkeypatch) -> None:
    class FakeResponse:
        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc_value, traceback):
            return False

        def read(self) -> bytes:
            return b'{"unexpected": []}'

    def fake_urlopen(request):
        return FakeResponse()

    monkeypatch.setattr(
        "app.processing.jina_provider.urlopen",
        fake_urlopen,
    )

    try:
        _provider().embed(["first chunk"])
    except JinaProviderError as exc:
        assert exc.retryable is False
    else:
        raise AssertionError("Expected JinaProviderError")
