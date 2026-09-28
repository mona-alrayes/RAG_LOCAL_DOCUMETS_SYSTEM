from dataclasses import dataclass
from pathlib import Path

from llama_cloud import LlamaCloud

from app.parsing.providers.base import BaseParsingProvider


@dataclass(frozen=True, slots=True)
class LlamaParsePage:
    page_number: int
    markdown: str


class LlamaParseProvider(BaseParsingProvider[LlamaParsePage]):
    CHECKPOINT_SIGNATURE = (
        "llamaparse-agentic-plus-cost-optimizer-latest-ar-en-markdown-v2"
    )

    def __init__(
        self,
        api_key: str,
        *,
        upload_timeout_seconds: float = 900.0,
        parse_timeout_seconds: float = 7200.0,
    ) -> None:
        api_key = api_key.strip()

        if not api_key:
            raise ValueError("LlamaParse API key must not be blank.")

        if upload_timeout_seconds <= 0:
            raise ValueError("LlamaParse upload timeout must be positive.")

        if parse_timeout_seconds <= 0:
            raise ValueError("LlamaParse parse timeout must be positive.")

        self._upload_timeout_seconds = upload_timeout_seconds
        self._parse_timeout_seconds = parse_timeout_seconds

        # Retrying an ambiguous POST may create another billable parsing job.
        self._client = LlamaCloud(api_key=api_key, max_retries=0)

    def parse(self, file_path: Path) -> list[LlamaParsePage]:
        return self.resume(self.submit(file_path))

    def submit(self, file_path: Path) -> str:
        cloud_file = self._client.files.create(
            file=file_path,
            purpose="parse",
            timeout=self._upload_timeout_seconds,
        )

        job = self._client.parsing.create(
            file_id=cloud_file.id,
            tier="agentic_plus",
            version="latest",
            output_options={
                "markdown": {
                    "tables": {
                        "output_tables_as_markdown": True,
                    }
                }
            },
            processing_options={
                "cost_optimizer": {
                    "enable": True,
                },
                "ocr_parameters": {
                    "languages": ["ar", "en"],
                }
            },
        )

        return job.id

    def resume(self, job_id: str) -> list[LlamaParsePage]:
        self._client.parsing.wait_for_completion(
            job_id,
            timeout=self._parse_timeout_seconds,
        )
        parse_result = self._client.parsing.get(
            job_id,
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
