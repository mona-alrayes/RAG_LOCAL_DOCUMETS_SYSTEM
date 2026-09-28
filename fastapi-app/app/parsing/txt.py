from pathlib import Path

from app.parsing.base import BaseDocumentLoader
from app.parsing.providers.base import BaseParsingProvider
from app.parsing.providers.llamaparse import LlamaParsePage


class TxtDocumentLoader(BaseDocumentLoader[LlamaParsePage]):
    def __init__(
        self,
        provider: BaseParsingProvider[LlamaParsePage],
    ) -> None:
        self._provider = provider

    def load(self, file_path: Path) -> list[LlamaParsePage]:
        return self._provider.parse(file_path)

    def submit(self, file_path: Path) -> str:
        return self._provider.submit(file_path)

    def resume(self, job_id: str) -> list[LlamaParsePage]:
        return self._provider.resume(job_id)
