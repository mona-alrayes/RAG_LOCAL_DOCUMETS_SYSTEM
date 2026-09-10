from dataclasses import dataclass
from pathlib import Path

from llama_cloud import LlamaCloud

from app.parsing.providers.base import BaseParsingProvider


@dataclass(frozen=True, slots=True)
class LlamaParsePage:
    page_number: int
    markdown: str


class LlamaParseProvider(BaseParsingProvider[LlamaParsePage]):
    def __init__(self, api_key: str) -> None:
        api_key = api_key.strip()

        if not api_key:
            raise ValueError("LlamaParse API key must not be blank.")

        self._client = LlamaCloud(api_key=api_key)

    def parse(self, file_path: Path) -> list[LlamaParsePage]:
        cloud_file = self._client.files.create(
            file=file_path,
            purpose="parse",
        )

        parse_result = self._client.parsing.parse(
            file_id=cloud_file.id,
            tier="agentic",
            version="latest",
            output_options={
                "markdown": {
                    "tables": {
                        "output_tables_as_markdown": True,
                    }
                }
            },
            processing_options={
                "ocr_parameters": {
                    "languages": ["ar", "en"],
                }
            },
            expand=["markdown"],
        )

        pages = parse_result.markdown.pages

        return [
            LlamaParsePage(
                page_number=page_number,
                markdown=page.markdown or "",
            )
            for page_number, page in enumerate(pages, start=1)
        ]
