from app.parsing.normalized import NormalizedDocument
from app.parsing.providers.llamaparse import LlamaParsePage


def normalize_llamaparse_pages(
    pages: list[LlamaParsePage],
    *,
    preserve_page_numbers: bool,
) -> list[NormalizedDocument]:
    return [
        NormalizedDocument(
            text=page.markdown,
            page=page.page_number if preserve_page_numbers else None,
            section=None,
        )
        for page in pages
    ]
