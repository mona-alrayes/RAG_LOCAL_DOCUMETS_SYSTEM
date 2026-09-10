from abc import ABC, abstractmethod
from pathlib import Path
from typing import Generic, TypeVar


ParseResultT = TypeVar("ParseResultT")


class BaseParsingProvider(ABC, Generic[ParseResultT]):
    @abstractmethod
    def parse(self, file_path: Path) -> list[ParseResultT]:
        """Parse a document and return provider-level parsing results."""
        raise NotImplementedError
