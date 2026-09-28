from pathlib import Path

from app.parsing.docx import DocxDocumentLoader
from app.parsing.providers.base import BaseParsingProvider
from app.parsing.providers.llamaparse import LlamaParsePage


class FakeParsingProvider(BaseParsingProvider[LlamaParsePage]):
    def __init__(self) -> None:
        self.received_path: Path | None = None
        self.parse_calls = 0
        self.result = [
            LlamaParsePage(
                page_number=1,
                markdown="# Test DOCX",
            )
        ]

    def parse(self, file_path: Path) -> list[LlamaParsePage]:
        self.parse_calls += 1
        self.received_path = file_path

        return self.result


def test_docx_loader_delegates_to_parsing_provider() -> None:
    provider = FakeParsingProvider()
    loader = DocxDocumentLoader(provider)
    file_path = Path("example.docx")

    result = loader.load(file_path)

    assert provider.received_path == file_path
    assert provider.parse_calls == 1
    assert result is provider.result
