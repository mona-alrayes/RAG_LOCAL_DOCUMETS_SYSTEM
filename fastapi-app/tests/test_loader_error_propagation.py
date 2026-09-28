from pathlib import Path

import pytest

from app.parsing.docx import DocxDocumentLoader
from app.parsing.pdf import PdfDocumentLoader
from app.parsing.providers.base import BaseParsingProvider
from app.parsing.providers.llamaparse import LlamaParsePage
from app.parsing.txt import TxtDocumentLoader


class FailingParsingProvider(BaseParsingProvider[LlamaParsePage]):
    def __init__(self, error: RuntimeError) -> None:
        self.error = error

    def parse(self, file_path: Path) -> list[LlamaParsePage]:
        raise self.error


@pytest.mark.parametrize(
    ("loader_class", "file_path"),
    [
        (PdfDocumentLoader, Path("example.pdf")),
        (DocxDocumentLoader, Path("example.docx")),
        (TxtDocumentLoader, Path("example.txt")),
    ],
)
def test_loader_propagates_parsing_provider_error(
    loader_class,
    file_path: Path,
) -> None:
    error = RuntimeError("provider failure")
    provider = FailingParsingProvider(error)
    loader = loader_class(provider)

    with pytest.raises(RuntimeError) as exc_info:
        loader.load(file_path)

    assert exc_info.value is error
