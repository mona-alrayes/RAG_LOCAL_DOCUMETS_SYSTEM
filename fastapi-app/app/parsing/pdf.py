from pathlib import Path

from app.parsing.base import BaseDocumentLoader
from app.parsing.providers.base import BaseParsingProvider
from app.parsing.providers.llamaparse import LlamaParsePage


class PdfDocumentLoader(BaseDocumentLoader[LlamaParsePage]):
    def __init__(
        self,
        provider: BaseParsingProvider[LlamaParsePage],
    ) -> None:
        self._provider = provider

    def load(self, file_path: Path) -> list[LlamaParsePage]:
        return self._provider.parse(file_path)
