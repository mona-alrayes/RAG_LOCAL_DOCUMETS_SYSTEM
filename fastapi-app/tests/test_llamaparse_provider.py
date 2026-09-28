from pathlib import Path
from types import SimpleNamespace

import pytest
from app.parsing.providers import llamaparse as llamaparse_module
from app.parsing.providers.llamaparse import (
    LlamaParsePage,
    LlamaParseProvider,
)


def test_llamaparse_provider_parses_markdown_pages(monkeypatch) -> None:
    class FakeFiles:
        def create(self, *, file: Path, purpose: str, timeout: float):
            assert file == Path("example.pdf")
            assert purpose == "parse"
            assert timeout == 900.0

            return SimpleNamespace(id="file-123")

    class FakeParsing:
        def create(self, **kwargs):
            assert kwargs == {
                "file_id": "file-123",
                "tier": "agentic_plus",
                "version": "latest",
                "output_options": {
                    "markdown": {
                        "tables": {
                            "output_tables_as_markdown": True,
                        }
                    }
                },
                "processing_options": {
                    "cost_optimizer": {
                        "enable": True,
                    },
                    "ocr_parameters": {
                        "languages": ["ar", "en"],
                    }
                },
            }

            return SimpleNamespace(id="job-123")

        def wait_for_completion(self, job_id: str, *, timeout: float):
            assert job_id == "job-123"
            assert timeout == 7200.0

            return SimpleNamespace(id=job_id, status="COMPLETED")

        def get(self, job_id: str, *, expand: list[str]):
            assert job_id == "job-123"
            assert expand == ["markdown"]

            return SimpleNamespace(
                job=SimpleNamespace(id=job_id, status="COMPLETED"),
                markdown=SimpleNamespace(
                    pages=[
                        SimpleNamespace(markdown="# الصفحة الأولى"),
                        SimpleNamespace(markdown=""),
                    ]
                )
            )

    class FakeClient:
        files = FakeFiles()
        parsing = FakeParsing()

    def fake_llama_cloud(*, api_key: str, max_retries: int):
        assert api_key == "test-key"
        assert max_retries == 0
        return FakeClient()

    monkeypatch.setattr(
        llamaparse_module,
        "LlamaCloud",
        fake_llama_cloud,
    )

    provider = LlamaParseProvider(api_key="test-key")

    result = provider.parse(Path("example.pdf"))

    assert result == [
        LlamaParsePage(
            page_number=1,
            markdown="# الصفحة الأولى",
        ),
        LlamaParsePage(
            page_number=2,
            markdown="",
        ),
    ]


def test_llamaparse_provider_rejects_blank_api_key() -> None:
    with pytest.raises(
        ValueError,
        match="LlamaParse API key must not be blank.",
    ):
        LlamaParseProvider(api_key="   ")
