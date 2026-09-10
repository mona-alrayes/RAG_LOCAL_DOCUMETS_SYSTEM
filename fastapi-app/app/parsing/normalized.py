from dataclasses import dataclass


@dataclass(frozen=True, slots=True)
class NormalizedDocument:
    text: str
    page: int | None = None
    section: str | None = None
