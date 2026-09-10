import json
import logging
from collections.abc import AsyncIterator, Callable
from contextlib import AbstractAsyncContextManager, aclosing, nullcontext
from functools import partial
from typing import TYPE_CHECKING, Protocol, Self

import httpx2

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.generation.base import GenerationOptions, LLMProvider
from app.generation.prompt_budget import prompt_tokens
from app.processing.base import ProcessingProfile
from app.runtime.state import local_model_coordinator_state
from app.services.prompt import BuiltPrompt

if TYPE_CHECKING:
    from app.runtime.model_coordinator import LocalModelCoordinator


class OllamaStreamResponse(Protocol):
    def raise_for_status(self) -> object: ...

    def aiter_lines(self) -> AsyncIterator[str]: ...


class OllamaHTTPClient(Protocol):
    async def __aenter__(self) -> Self: ...

    async def __aexit__(
        self,
        exc_type: object,
        exc: object,
        traceback: object,
    ) -> None: ...

    def stream(
        self,
        method: str,
        url: str,
        *,
        json: dict[str, object],
    ) -> AbstractAsyncContextManager[OllamaStreamResponse]: ...


class OllamaLLMProvider(LLMProvider):
    def __init__(
        self,
        *,
        client_factory: Callable[..., OllamaHTTPClient],
        base_url: str,
        model: str,
        keep_alive: str,
        timeout_seconds: float,
        num_ctx: int = 8192,
        max_tokens: int = 768,
        coordinator: "LocalModelCoordinator | None" = None,
        count_prompt_tokens: Callable[[BuiltPrompt], int] = prompt_tokens,
    ) -> None:
        self._count_prompt_tokens = count_prompt_tokens
        self._coordinator = coordinator
        self._num_ctx = num_ctx
        self._max_tokens = max_tokens
        self._client_factory = client_factory
        self._base_url = base_url
        self._model = model
        self._keep_alive = keep_alive
        self._timeout_seconds = timeout_seconds

    @property
    def profile(self) -> ProcessingProfile:
        return ProcessingProfile.HYBRID_LOCAL

    @property
    def capability_name(self) -> str:
        return "ollama_llm"

    async def stream(
        self,
        *,
        prompt: BuiltPrompt,
        options: GenerationOptions,
    ) -> AsyncIterator[str]:
        count = self._count_prompt_tokens(prompt)
        if count + self._max_tokens > self._num_ctx:
            raise ApplicationException(code='local_prompt_too_large',
                message='Prompt and answer exceed the configured local context budget.')
        logging.getLogger('uvicorn.error.memory').info(
            'generation_budget prompt_tokens_upper_bound=%s num_ctx=%s num_predict=%s',
            count, self._num_ctx, self._max_tokens)
        context = (self._coordinator.generation_lease()
                   if self._coordinator is not None else nullcontext())
        async with context, aclosing(self._stream_response(prompt=prompt, options=options)) as stream:
            async for token in stream:
                yield token

    async def _stream_response(self, *, prompt: BuiltPrompt, options: GenerationOptions) -> AsyncIterator[str]:
        payload: dict[str, object] = {
            "model": self._model,
            "messages": [
                {
                    "role": "system",
                    "content": prompt.system_instructions,
                },
                {
                    "role": "user",
                    "content": prompt.user_content,
                },
            ],
            "stream": True,
            "think": False,
            "keep_alive": self._keep_alive,
            "options": {
                "temperature": options.temperature,
                "num_ctx": self._num_ctx,
                "num_predict": self._max_tokens,
            },
        }

        client = self._client_factory(
            base_url=self._base_url,
            timeout=self._timeout_seconds,
            follow_redirects=False,
            trust_env=False,
        )

        stream_started = False
        emitted_content = False

        try:
            async with client, client.stream(
                "POST",
                "/api/chat",
                json=payload,
            ) as response:
                response.raise_for_status()
                stream_started = True

                async for line in response.aiter_lines():
                    if line == "":
                        continue

                    content = self._extract_content(line)

                    if content is None or content == "":
                        continue

                    emitted_content = True
                    yield content

        except ApplicationException:
            raise
        except (
            httpx2.HTTPError,
            httpx2.InvalidURL,
            httpx2.StreamError,
        ):
            if stream_started:
                raise ApplicationException(
                    code="ollama_llm_stream_failed",
                    message="Ollama LLM stream failed.",
                ) from None

            raise ApplicationException(
                code="ollama_llm_request_failed",
                message="Ollama LLM request failed.",
            ) from None

        if not emitted_content:
            raise ApplicationException(
                code="ollama_llm_empty_stream",
                message="Ollama LLM returned an empty stream.",
            )

    @classmethod
    def _extract_content(
        cls,
        line: str,
    ) -> str | None:
        try:
            chunk = json.loads(line)
        except json.JSONDecodeError:
            raise cls._malformed_stream_error() from None

        if not isinstance(chunk, dict):
            raise cls._malformed_stream_error()

        if "error" in chunk:
            raise ApplicationException(
                code="ollama_llm_stream_failed",
                message="Ollama LLM stream failed.",
            )

        if chunk.get('done_reason') == 'length':
            raise ApplicationException(code='ollama_llm_output_limit',
                message='The local answer reached the configured output limit.')
        if chunk.get('done') is True:
            logging.getLogger('uvicorn.error.memory').info('ollama_completion %s', json.dumps({
                k: chunk.get(k) for k in ('load_duration', 'prompt_eval_count',
                    'prompt_eval_duration', 'eval_count', 'eval_duration', 'total_duration')
            }))
        message = chunk.get("message")

        if (
            not isinstance(message, dict)
            or "content" not in message
        ):
            raise cls._malformed_stream_error()

        content = message["content"]

        if content is None or content == "":
            return None

        if not isinstance(content, str):
            raise cls._malformed_stream_error()

        return content

    @staticmethod
    def _malformed_stream_error() -> ApplicationException:
        return ApplicationException(
            code="ollama_llm_malformed_stream",
            message=(
                "Ollama LLM returned a malformed "
                "streamed response."
            ),
        )


def build_ollama_llm_provider(
    settings: Settings,
    *,
    client_factory: Callable[
        ...,
        OllamaHTTPClient,
    ] = httpx2.AsyncClient,
) -> OllamaLLMProvider:
    base_url = settings.ollama_base_url.strip()
    model = settings.local_llm_model.strip()

    if not base_url or not model:
        raise ApplicationException(
            code="ollama_llm_not_configured",
            message="Ollama LLM is not configured.",
        )

    return OllamaLLMProvider(
        client_factory=client_factory,
        base_url=base_url.rstrip("/"),
        model=model,
        keep_alive=settings.ollama_keep_alive,
        num_ctx=settings.local_llm_num_ctx,
        max_tokens=settings.rag_generation_max_tokens,
        coordinator=local_model_coordinator_state.get(),
        count_prompt_tokens=partial(prompt_tokens, tokenizer_path=settings.local_llm_tokenizer_path),
        timeout_seconds=(
            settings.ollama_request_timeout_seconds
        ),
    )