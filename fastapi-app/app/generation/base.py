from abc import ABC, abstractmethod
from collections.abc import AsyncIterator
from dataclasses import dataclass

from app.processing.base import ProcessingProfile
from app.services.prompt import BuiltPrompt


@dataclass(frozen=True, slots=True)
class GenerationOptions:
    temperature: float


class LLMProvider(ABC):
    @property
    @abstractmethod
    def profile(self) -> ProcessingProfile:
        """Return the trusted processing profile handled by this provider."""
        raise NotImplementedError

    @property
    @abstractmethod
    def capability_name(self) -> str:
        """Return the provider name used by the capabilities contract."""
        raise NotImplementedError

    @abstractmethod
    def stream(
        self,
        *,
        prompt: BuiltPrompt,
        options: GenerationOptions,
    ) -> AsyncIterator[str]:
        """Stream generated answer tokens."""
        raise NotImplementedError