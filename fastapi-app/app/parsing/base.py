from abc import ABC, abstractmethod
from pathlib import Path
from typing import Generic, TypeVar


DocumentT = TypeVar("DocumentT")


class BaseDocumentLoader(ABC, Generic[DocumentT]):
    @abstractmethod
    def load(self, file_path: Path) -> list[DocumentT]:
        """Load a document and return normalized document items."""
        raise NotImplementedError
