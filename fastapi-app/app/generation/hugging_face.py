from collections.abc import (
    AsyncIterable,
    AsyncIterator,
    Callable,
)
from typing import Protocol

from huggingface_hub import AsyncInferenceClient
from huggingface_hub.errors import HfHubHTTPError, InferenceTimeoutError

from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.generation.base import GenerationOptions, LLMProvider
from app.processing.base import ProcessingProfile
from app.services.prompt import BuiltPrompt


class HuggingFaceChatClient(Protocol):
    async def chat_completion(
        self,
        messages: list[dict[str, str]],
        *,
        model: str,
        stream: bool,
        temperature: float,
        extra_body: dict[str, object] | None = None,
    ) -> AsyncIterable[object]: ...


class HuggingFaceLLMProvider(LLMProvider):
    def __init__(
        self,
        *,
        client: HuggingFaceChatClient,
        model: str,
    ) -> None:
        self._client = client
        self._model = model

    @property
    def profile(self) -> ProcessingProfile:
        return ProcessingProfile.CLOUD

    @property
    def capability_name(self) -> str:
        return "hugging_face_llm"

    async def stream(
        self,
        *,
        prompt: BuiltPrompt,
        options: GenerationOptions,
    ) -> AsyncIterator[str]:
        messages = [
            {
                "role": "system",
                "content": prompt.system_instructions,
            },
            {
                "role": "user",
                "content": prompt.user_content,
            },
        ]

        try:
            response = await self._client.chat_completion(
                messages=messages,
                model=self._model,
                stream=True,
                temperature=options.temperature,
                extra_body={
                    "chat_template_kwargs": {
                        "enable_thinking": False,
                    },
                },
            )
        except (HfHubHTTPError, InferenceTimeoutError):
            raise ApplicationException(
                code="hugging_face_llm_request_failed",
                message="Hugging Face LLM request failed.",
            ) from None

        if not isinstance(response, AsyncIterable):
            raise self._malformed_stream_error()

        emitted_content = False

        try:
            async for chunk in response:
                content = self._extract_content(chunk)

                if content is None or content == "":
                    continue

                emitted_content = True
                yield content
        except ApplicationException:
            raise
        except (HfHubHTTPError, InferenceTimeoutError):
            raise ApplicationException(
                code="hugging_face_llm_stream_failed",
                message="Hugging Face LLM stream failed.",
            ) from None

        if not emitted_content:
            raise ApplicationException(
                code="hugging_face_llm_empty_stream",
                message="Hugging Face LLM returned an empty stream.",
            )

    @classmethod
    def _extract_content(
        cls,
        chunk: object,
    ) -> str | None:
        choices = getattr(chunk, "choices", None)

        # Some inference providers end with a usage-only chunk after all text.
        if choices == [] and getattr(chunk, "usage", None) is not None:
            return None

        if (
            not isinstance(choices, list)
            or not choices
        ):
            raise cls._malformed_stream_error()

        delta = getattr(choices[0], "delta", None)

        if delta is None or not hasattr(delta, "content"):
            raise cls._malformed_stream_error()

        content = delta.content

        if content is None or content == "":
            return None

        if not isinstance(content, str):
            raise cls._malformed_stream_error()

        return content

    @staticmethod
    def _malformed_stream_error() -> ApplicationException:
        return ApplicationException(
            code="hugging_face_llm_malformed_stream",
            message=(
                "Hugging Face LLM returned a malformed "
                "streamed response."
            ),
        )


def build_hugging_face_llm_provider(
    settings: Settings,
    *,
    client_factory: Callable[
        ...,
        HuggingFaceChatClient,
    ] = AsyncInferenceClient,
) -> HuggingFaceLLMProvider:
    secret = settings.hf_token

    if secret is None:
        raise ApplicationException(
            code="hugging_face_llm_not_configured",
            message="Hugging Face LLM is not configured.",
        )

    token = secret.get_secret_value().strip()

    if not token:
        raise ApplicationException(
            code="hugging_face_llm_not_configured",
            message="Hugging Face LLM is not configured.",
        )

    client = client_factory(
        provider="auto",
        token=token,
    )

    return HuggingFaceLLMProvider(
        client=client,
        model=settings.cloud_llm_model,
    )
