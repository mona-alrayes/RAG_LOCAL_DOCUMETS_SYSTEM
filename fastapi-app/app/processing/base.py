from abc import ABC, abstractmethod
from enum import StrEnum


class ProcessingProfile(StrEnum):
    CLOUD = "cloud"
    HYBRID_LOCAL = "hybrid_local"


class BaseProcessingProfile(ABC):
    @property
    @abstractmethod
    def profile(self) -> ProcessingProfile:
        """Return the processing profile represented by this implementation."""
        raise NotImplementedError
