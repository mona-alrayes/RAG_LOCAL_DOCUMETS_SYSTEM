import asyncio
import json
from collections.abc import AsyncIterator
from dataclasses import dataclass
from typing import Self

import httpx2
import pytest

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.generation.base import GenerationOptions
from app.generation.configuration import (
    generation_options_from_settings,
)
from app.generation.ollama import (
    OllamaLLMProvider,
    build_ollama_llm_provider,
)
from app.processing.base import ProcessingProfile
from app.services.prompt import BuiltPrompt


@dataclass
class FakeResponse:
    lines: list[str]
    stream_error: BaseException | None = None

    def raise_for_status(self) -> object:
        return self

    async def aiter_lines(self) -> AsyncIterator[str]:
        for line in self.lines:
            yield line

        if self.stream_error is not None:
            raise self.stream_error


class FakeStreamContext:
    def __init__(
        self,
        *,
        response: FakeResponse,
        request_error: BaseException | None = None,
    ) -> None:
        self.response = response
        self.request_error = request_error

    async def __aenter__(self) -> FakeResponse:
        if self.request_error is not None:
            raise self.request_error

        return self.response

    async def __aexit__(
        self,
        exc_type: object,
        exc: object,
        traceback: object,
    ) -> None:
        return None


class FakeAsyncClient:
    def __init__(
        self,
        *,
        lines: list[str] | None = None,
        request_error: BaseException | None = None,
        stream_error: BaseException | None = None,
    ) -> None:
        self.response = FakeResponse(
            lines=lines or [],
            stream_error=stream_error,
        )
        self.request_error = request_error
        self.calls: list[dict[str, object]] = []

    async def __aenter__(self) -> Self:
        return self

    async def __aexit__(
        self,
        exc_type: object,
        exc: object,
        traceback: object,
    ) -> None:
        return None

    def stream(
        self,
        method: str,
        url: str,
        *,
        json: dict[str, object],
    ) -> FakeStreamContext:
        self.calls.append(
            {
                "method": method,
                "url": url,
                "json": json,
            }
        )

        return FakeStreamContext(
            response=self.response,
            request_error=self.request_error,
        )


def ollama_chunk(
    content: object,
    *,
    done: bool = False,
) -> str:
    return json.dumps(
        {
            "model": "qwen3.5:4b",
            "message": {
                "role": "assistant",
                "content": content,
            },
            "done": done,
        },
        ensure_ascii=False,
    )


def prompt() -> BuiltPrompt:
    return BuiltPrompt(
        system_instructions="System instructions",
        user_content="User content",
    )


async def collect_tokens(
    stream: AsyncIterator[str],
) -> list[str]:
    return [token async for token in stream]


def provider_with_client(
    client: FakeAsyncClient,
) -> OllamaLLMProvider:
    return OllamaLLMProvider(
        client_factory=lambda **kwargs: client,
        base_url="http://127.0.0.1:11434",
        model="qwen3.5:4b",
        keep_alive="0",
        timeout_seconds=300,
    )


def test_provider_contract_and_stream_request() -> None:
    client = FakeAsyncClient(
        lines=[
            ollama_chunk("مرحبا"),
            ollama_chunk(" بالعالم"),
            ollama_chunk("", done=True),
        ]
    )
    provider = provider_with_client(client)

    tokens = asyncio.run(
        collect_tokens(
            provider.stream(
                prompt=prompt(),
                options=GenerationOptions(
                    temperature=0.2,
                ),
            )
        )
    )

    assert provider.profile is ProcessingProfile.HYBRID_LOCAL
    assert provider.capability_name == "ollama_llm"

    assert tokens == [
        "مرحبا",
        " بالعالم",
    ]

    assert len(client.calls) == 1

    call = client.calls[0]

    assert call["method"] == "POST"
    assert call["url"] == "/api/chat"

    payload = call["json"]

    assert isinstance(payload, dict)
    assert payload["model"] == "qwen3.5:4b"
    assert payload["stream"] is True
    assert payload["think"] is False
    assert payload["keep_alive"] == "0"

    assert payload["options"] == {
        "temperature": 0.2,
        "num_ctx": 8192,
        "num_predict": 768,
    }

    assert payload["messages"] == [
        {
            "role": "system",
            "content": "System instructions",
        },
        {
            "role": "user",
            "content": "User content",
        },
    ]


def test_stream_preserves_spaces_and_ignores_only_empty_content() -> None:
    client = FakeAsyncClient(
        lines=[
            ollama_chunk(None),
            ollama_chunk(""),
            ollama_chunk(" "),
            ollama_chunk("word"),
            ollama_chunk("  "),
        ]
    )
    provider = provider_with_client(client)

    tokens = asyncio.run(
        collect_tokens(
            provider.stream(
                prompt=prompt(),
                options=GenerationOptions(
                    temperature=0.2,
                ),
            )
        )
    )

    assert tokens == [
        " ",
        "word",
        "  ",
    ]


def test_request_failure_is_safe_and_not_retried() -> None:
    secret = "ollama-request-secret"
    prompt_content = "sensitive prompt"

    client = FakeAsyncClient(
        request_error=httpx2.ConnectError(secret),
    )
    provider = provider_with_client(client)

    with pytest.raises(
        ApplicationException,
    ) as exc_info:
        asyncio.run(
            collect_tokens(
                provider.stream(
                    prompt=BuiltPrompt(
                        system_instructions=prompt_content,
                        user_content=prompt_content,
                    ),
                    options=GenerationOptions(
                        temperature=0.2,
                    ),
                )
            )
        )

    assert (
        exc_info.value.code
        == "ollama_llm_request_failed"
    )
    assert secret not in str(exc_info.value)
    assert prompt_content not in str(exc_info.value)
    assert secret not in repr(exc_info.value)
    assert exc_info.value.__cause__ is None
    assert len(client.calls) == 1


def test_stream_failure_is_safe_and_not_retried() -> None:
    secret = "ollama-stream-secret"

    client = FakeAsyncClient(
        lines=[
            ollama_chunk("partial"),
        ],
        stream_error=httpx2.ReadError(secret),
    )
    provider = provider_with_client(client)

    with pytest.raises(
        ApplicationException,
    ) as exc_info:
        asyncio.run(
            collect_tokens(
                provider.stream(
                    prompt=prompt(),
                    options=GenerationOptions(
                        temperature=0.2,
                    ),
                )
            )
        )

    assert (
        exc_info.value.code
        == "ollama_llm_stream_failed"
    )
    assert secret not in str(exc_info.value)
    assert secret not in repr(exc_info.value)
    assert exc_info.value.__cause__ is None
    assert len(client.calls) == 1


def test_ollama_error_chunk_fails_safely() -> None:
    secret = "ollama-provider-secret"

    client = FakeAsyncClient(
        lines=[
            ollama_chunk("partial"),
            json.dumps(
                {
                    "error": secret,
                }
            ),
        ]
    )
    provider = provider_with_client(client)

    with pytest.raises(
        ApplicationException,
    ) as exc_info:
        asyncio.run(
            collect_tokens(
                provider.stream(
                    prompt=prompt(),
                    options=GenerationOptions(
                        temperature=0.2,
                    ),
                )
            )
        )

    assert (
        exc_info.value.code
        == "ollama_llm_stream_failed"
    )
    assert secret not in str(exc_info.value)
    assert secret not in repr(exc_info.value)
    assert len(client.calls) == 1


@pytest.mark.parametrize(
    "line",
    [
        "not-json",
        json.dumps([]),
        json.dumps({"done": True}),
        json.dumps(
            {
                "message": {
                    "content": 123,
                }
            }
        ),
    ],
)
def test_malformed_streamed_response_fails_safely(
    line: str,
) -> None:
    client = FakeAsyncClient(
        lines=[line],
    )
    provider = provider_with_client(client)

    with pytest.raises(
        ApplicationException,
    ) as exc_info:
        asyncio.run(
            collect_tokens(
                provider.stream(
                    prompt=prompt(),
                    options=GenerationOptions(
                        temperature=0.2,
                    ),
                )
            )
        )

    assert (
        exc_info.value.code
        == "ollama_llm_malformed_stream"
    )
    assert len(client.calls) == 1


def test_empty_stream_fails_safely() -> None:
    client = FakeAsyncClient(
        lines=[
            "",
            ollama_chunk(None),
            ollama_chunk("", done=True),
        ]
    )
    provider = provider_with_client(client)

    with pytest.raises(
        ApplicationException,
    ) as exc_info:
        asyncio.run(
            collect_tokens(
                provider.stream(
                    prompt=prompt(),
                    options=GenerationOptions(
                        temperature=0.2,
                    ),
                )
            )
        )

    assert (
        exc_info.value.code
        == "ollama_llm_empty_stream"
    )
    assert len(client.calls) == 1


def test_cancelled_error_is_not_converted_to_provider_failure() -> None:
    client = FakeAsyncClient(
        request_error=asyncio.CancelledError(),
    )
    provider = provider_with_client(client)

    with pytest.raises(asyncio.CancelledError):
        asyncio.run(
            collect_tokens(
                provider.stream(
                    prompt=prompt(),
                    options=GenerationOptions(
                        temperature=0.2,
                    ),
                )
            )
        )

    assert len(client.calls) == 1


def test_settings_read_ollama_generation_configuration(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path,
) -> None:
    monkeypatch.chdir(tmp_path)

    monkeypatch.setenv(
        "OLLAMA_BASE_URL",
        "http://127.0.0.1:11434",
    )
    monkeypatch.setenv(
        "LOCAL_LLM_MODEL",
        "qwen3.5:4b",
    )
    monkeypatch.setenv(
        "OLLAMA_REQUEST_TIMEOUT_SECONDS",
        "240",
    )
    monkeypatch.setenv(
        "OLLAMA_KEEP_ALIVE",
        "3m",
    )
    monkeypatch.setenv(
        "RAG_GENERATION_TEMPERATURE",
        "0.37",
    )

    settings = Settings()

    assert (
        settings.ollama_base_url
        == "http://127.0.0.1:11434"
    )
    assert settings.local_llm_model == "qwen3.5:4b"
    assert settings.ollama_request_timeout_seconds == 240
    assert settings.ollama_keep_alive == "3m"
    assert settings.rag_generation_temperature == 0.37

    options = generation_options_from_settings(
        settings
    )

    assert options.temperature == 0.37


def test_production_builder_uses_server_settings_and_injected_client() -> None:
    captured: dict[str, object] = {}

    client = FakeAsyncClient(
        lines=[
            ollama_chunk("ok"),
        ]
    )

    def fake_factory(
        **kwargs: object,
    ) -> FakeAsyncClient:
        captured.update(kwargs)
        return client

    settings = Settings(
        ollama_base_url="http://127.0.0.1:11434/",
        local_llm_model="qwen3.5:4b",
        ollama_request_timeout_seconds=180,
        ollama_keep_alive="3m",
        rag_generation_temperature=0.41,
        rag_generation_max_tokens=1536,
    )

    provider = build_ollama_llm_provider(
        settings,
        client_factory=fake_factory,
    )

    tokens = asyncio.run(
        collect_tokens(
            provider.stream(
                prompt=prompt(),
                options=(
                    generation_options_from_settings(
                        settings
                    )
                ),
            )
        )
    )

    assert tokens == ["ok"]

    assert captured == {
        "base_url": "http://127.0.0.1:11434",
        "timeout": 180,
        "follow_redirects": False,
        "trust_env": False,
    }

    assert len(client.calls) == 1

    assert client.calls[0]["json"] == {
        "model": "qwen3.5:4b",
        "messages": [
            {
                "role": "system",
                "content": "System instructions",
            },
            {
                "role": "user",
                "content": "User content",
            },
        ],
        "stream": True,
        "think": False,
        "keep_alive": "3m",
        "options": {
            "temperature": 0.41,
            "num_ctx": 8192,
            "num_predict": 1536,
        },
    }


@pytest.mark.parametrize(
    ("base_url", "model"),
    [
        ("", "qwen3.5:4b"),
        ("   ", "qwen3.5:4b"),
        ("http://127.0.0.1:11434", ""),
        ("http://127.0.0.1:11434", "   "),
    ],
)
def test_builder_rejects_incomplete_ollama_configuration(
    base_url: str,
    model: str,
) -> None:
    settings = Settings(
        ollama_base_url=base_url,
        local_llm_model=model,
    )

    with pytest.raises(
        ApplicationException,
    ) as exc_info:
        build_ollama_llm_provider(
            settings,
            client_factory=lambda **kwargs: FakeAsyncClient(),
        )

    assert (
        exc_info.value.code
        == "ollama_llm_not_configured"
    )


def test_oversized_prompt_fails_before_ollama_request():
    client = FakeAsyncClient(lines=[ollama_chunk('bad')])
    with pytest.raises(ApplicationException) as error:
        asyncio.run(collect_tokens(provider_with_client(client).stream(
            prompt=BuiltPrompt(system_instructions='rules', user_content='x' * 9000),
            options=GenerationOptions(temperature=0.2))))
    assert error.value.code == 'local_prompt_too_large'
    assert client.calls == []


def test_output_limit_is_not_reported_as_complete_answer():
    client = FakeAsyncClient(lines=[ollama_chunk('partial'), json.dumps({
        'message': {'content': ''}, 'done': True, 'done_reason': 'length'})])
    with pytest.raises(ApplicationException) as error:
        asyncio.run(collect_tokens(provider_with_client(client).stream(
            prompt=prompt(), options=GenerationOptions(temperature=0.2))))
    assert error.value.code == 'ollama_llm_output_limit'


def test_env_context_and_output_limits_reach_ollama(monkeypatch):
    monkeypatch.setenv('LOCAL_LLM_NUM_CTX', '4096')
    monkeypatch.setenv('RAG_GENERATION_MAX_TOKENS', '256')
    settings = Settings(_env_file=None)
    client = FakeAsyncClient(lines=[ollama_chunk('ok', done=True)])
    provider = build_ollama_llm_provider(settings, client_factory=lambda **kw: client)
    asyncio.run(collect_tokens(provider.stream(prompt=prompt(), options=GenerationOptions(temperature=0.2))))
    assert client.calls[0]['json']['options']['num_ctx'] == 4096
    assert client.calls[0]['json']['options']['num_predict'] == 256
