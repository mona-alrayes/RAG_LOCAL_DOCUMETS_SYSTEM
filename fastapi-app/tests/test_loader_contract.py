from pathlib import Path

from app.parsing.base import BaseDocumentLoader


class FakeDocumentLoader(BaseDocumentLoader[str]):
    def load(self, file_path: Path) -> list[str]:
        return [file_path.name]


def test_loader_contract_can_be_implemented() -> None:
    loader = FakeDocumentLoader()

    assert loader.load(Path("example.pdf")) == ["example.pdf"]
