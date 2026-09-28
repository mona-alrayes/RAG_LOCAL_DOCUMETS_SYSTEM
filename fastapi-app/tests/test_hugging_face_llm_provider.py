import asyncio
from collections.abc import AsyncIterator
from dataclasses import dataclass

import pytest
from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.generation.base import GenerationOptions
from app.generation.configuration import (
    generation_options_from_settings,
)
from app.generation.hugging_face import (
    HuggingFaceLLMProvider,
    build_hugging_face_llm_provider,
)
from app.processing.base import ProcessingProfile
from app.services.prompt import BuiltPrompt
from huggingface_hub.errors import InferenceTimeoutError
from pydantic import ValidationError


@dataclass
class FakeDelta:
    content: object


@dataclass
class FakeChoice:
    delta: object


@dataclass
class FakeChunk:
    choices: list[object]


class FakeAsyncClient:
    def __init__(
        self,
        *,
        chunks: list[object] | None = None,
        request_error: BaseException | None = None,
        stream_error: BaseException | None = None,
    ) -> None:
        self.chunks = chunks or []
        self.request_error = request_error
        self.stream_error = stream_error
        self.calls: list[dict[str, object]] = []

    async def chat_completion(
        self,
        **kwargs: object,
    ) -> AsyncIterator[object]:
        self.calls.append(dict(kwargs))

        if self.request_error is not None:
            raise self.request_error

        async def generate() -> AsyncIterator[object]:
            for chunk in self.chunks:
                yield chunk

            if self.stream_error is not None:
                raise self.stream_error

        return generate()


def chunk(content: object) -> FakeChunk:
    return FakeChunk(
        choices=[
            FakeChoice(
                delta=FakeDelta(content=content),
            )
        ]
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


def test_provider_contract_and_stream_request() -> None:
    client = FakeAsyncClient(
        chunks=[
            chunk("مرحبا"),
            chunk(" بالعالم"),
        ]
    )
    provider = HuggingFaceLLMProvider(
        client=client,
        model="Qwen/Qwen3.5-9B",
    )

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

    assert provider.profile is ProcessingProfile.CLOUD
    assert provider.capability_name == "hugging_face_llm"

    assert tokens == [
        "مرحبا",
        " بالعالم",
    ]

    assert len(client.calls) == 1

    call = client.calls[0]

    assert call["model"] == "Qwen/Qwen3.5-9B"
    assert call["stream"] is True
    assert call["temperature"] == 0.2

    assert call["messages"] == [
        {
            "role": "system",
            "content": "System instructions",
        },
        {
            "role": "user",
            "content": "User content",
        },
    ]

    assert call["extra_body"] == {
        "chat_template_kwargs": {
            "enable_thinking": False,
        }
    }


def test_stream_preserves_spaces_and_ignores_only_empty_deltas() -> None:
    client = FakeAsyncClient(
        chunks=[
            chunk(None),
            chunk(""),
            chunk(" "),
            chunk("word"),
            chunk("  "),
        ]
    )
    provider = HuggingFaceLLMProvider(
        client=client,
        model="Qwen/Qwen3.5-9B",
    )

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
    secret = "hf-super-secret"
    prompt_content = "sensitive prompt"

    client = FakeAsyncClient(
        request_error=InferenceTimeoutError(
            f"{secret}: {prompt_content}"
        )
    )
    provider = HuggingFaceLLMProvider(
        client=client,
        model="Qwen/Qwen3.5-9B",
    )

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
        == "hugging_face_llm_request_failed"
    )
    assert secret not in str(exc_info.value)
    assert prompt_content not in str(exc_info.value)
    assert secret not in repr(exc_info.value)
    assert exc_info.value.__cause__ is None
    assert len(client.calls) == 1


def test_stream_failure_is_safe_and_not_retried() -> None:
    secret = "hf-stream-secret"

    client = FakeAsyncClient(
        chunks=[chunk("partial")],
        stream_error=InferenceTimeoutError(secret),
    )
    provider = HuggingFaceLLMProvider(
        client=client,
        model="Qwen/Qwen3.5-9B",
    )

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
        == "hugging_face_llm_stream_failed"
    )
    assert secret not in str(exc_info.value)
    assert exc_info.value.__cause__ is None
    assert len(client.calls) == 1


def test_malformed_streamed_chunk_fails_safely() -> None:
    client = FakeAsyncClient(
        chunks=[
            FakeChunk(choices=[]),
        ]
    )
    provider = HuggingFaceLLMProvider(
        client=client,
        model="Qwen/Qwen3.5-9B",
    )

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
        == "hugging_face_llm_malformed_stream"
    )
    assert len(client.calls) == 1


def test_non_string_delta_is_malformed() -> None:
    client = FakeAsyncClient(
        chunks=[
            chunk(123),
        ]
    )
    provider = HuggingFaceLLMProvider(
        client=client,
        model="Qwen/Qwen3.5-9B",
    )

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
        == "hugging_face_llm_malformed_stream"
    )



def test_non_async_iterable_response_is_malformed() -> None:
    class FakeMalformedResponseClient:
        def __init__(self) -> None:
            self.calls: list[dict[str, object]] = []

        async def chat_completion(
            self,
            **kwargs: object,
        ) -> object:
            self.calls.append(dict(kwargs))
            return object()

    client = FakeMalformedResponseClient()
    provider = HuggingFaceLLMProvider(
        client=client,
        model="Qwen/Qwen3.5-9B",
    )

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
        == "hugging_face_llm_malformed_stream"
    )
    assert len(client.calls) == 1


def test_empty_stream_fails_safely() -> None:
    client = FakeAsyncClient()
    provider = HuggingFaceLLMProvider(
        client=client,
        model="Qwen/Qwen3.5-9B",
    )

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
        == "hugging_face_llm_empty_stream"
    )
    assert len(client.calls) == 1


def test_cancelled_error_is_not_converted_to_provider_failure() -> None:
    client = FakeAsyncClient(
        request_error=asyncio.CancelledError(),
    )
    provider = HuggingFaceLLMProvider(
        client=client,
        model="Qwen/Qwen3.5-9B",
    )

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


def test_settings_read_generation_configuration(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path,
) -> None:
    monkeypatch.chdir(tmp_path)

    secret = "hf-test-secret"

    monkeypatch.setenv("HF_TOKEN", secret)
    monkeypatch.setenv(
        "CLOUD_LLM_MODEL",
        "Qwen/Qwen3.5-9B",
    )
    monkeypatch.setenv(
        "RAG_GENERATION_TEMPERATURE",
        "0.37",
    )

    settings = Settings()

    assert settings.hf_token is not None
    assert (
        settings.hf_token.get_secret_value()
        == secret
    )
    assert (
        settings.cloud_llm_model
        == "Qwen/Qwen3.5-9B"
    )
    assert settings.rag_generation_temperature == 0.37

    options = generation_options_from_settings(
        settings
    )

    assert options.temperature == 0.37
    assert secret not in repr(settings)


@pytest.mark.parametrize(
    "temperature",
    [-0.01, 2.01],
)
def test_generation_temperature_is_bounded(
    temperature: float,
) -> None:
    with pytest.raises(ValidationError):
        Settings(
            rag_generation_temperature=temperature,
        )


def test_production_builder_uses_server_settings_and_injected_client() -> None:
    secret = "hf-builder-secret"
    captured: dict[str, object] = {}

    client = FakeAsyncClient(
        chunks=[chunk("ok")]
    )

    def fake_factory(
        **kwargs: object,
    ) -> FakeAsyncClient:
        captured.update(kwargs)
        return client

    settings = Settings(
        hf_token=secret,
        cloud_llm_model="Qwen/Qwen3.5-9B",
        rag_generation_temperature=0.41,
    )

    provider = build_hugging_face_llm_provider(
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
        "provider": "auto",
        "token": secret,
    }

    assert client.calls[0]["model"] == "Qwen/Qwen3.5-9B"
    assert client.calls[0]["temperature"] == 0.41

    assert secret not in repr(provider)
    assert secret not in repr(settings)


def test_builder_rejects_blank_hf_token() -> None:
    settings = Settings(
        hf_token="   ",
    )

    with pytest.raises(
        ApplicationException,
    ) as exc_info:
        build_hugging_face_llm_provider(
            settings,
            client_factory=lambda **kwargs: FakeAsyncClient(),
        )

    assert (
        exc_info.value.code
        == "hugging_face_llm_not_configured"
    )
    assert "token" not in exc_info.value.message.lower()


def test_usage_only_trailer_preserves_completed_answer() -> None:
    from types import SimpleNamespace

    provider = HuggingFaceLLMProvider(
        client=FakeAsyncClient(chunks=[chunk('OK'), SimpleNamespace(choices=[], usage={'total_tokens': 3})]),
        model='Qwen/Qwen3.5-9B',
    )
    assert asyncio.run(collect_tokens(provider.stream(prompt=prompt(), options=GenerationOptions(temperature=0)))) == ['OK']
