from abc import ABC, abstractmethod
from pathlib import Path
from typing import Generic, Protocol, TypeVar, runtime_checkable

DocumentT = TypeVar("DocumentT")


class BaseDocumentLoader(ABC, Generic[DocumentT]):
    @abstractmethod
    def load(self, file_path: Path) -> list[DocumentT]:
        """Load a document and return normalized document items."""
        raise NotImplementedError


@runtime_checkable
class ResumableDocumentLoader(Protocol[DocumentT]):
    def submit(self, file_path: Path) -> str:
        """Submit a document once and return its durable provider job ID."""
        ...

    def resume(self, job_id: str) -> list[DocumentT]:
        """Resume polling and retrieve results for an existing provider job."""
        ...
