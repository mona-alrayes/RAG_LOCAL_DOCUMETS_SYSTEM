from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class NormalizedChunk:
    text: str
    page: int | None = None
    section: str | None = None
