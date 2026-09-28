from app.parsing.normalization import normalize_llamaparse_pages
from app.parsing.normalized import NormalizedDocument
from app.parsing.providers.llamaparse import LlamaParsePage


def test_normalized_document_allows_missing_page_and_section() -> None:
    document = NormalizedDocument(
        text="نص الوثيقة",
        page=None,
        section=None,
    )

    assert document.text == "نص الوثيقة"
    assert document.page is None
    assert document.section is None


def test_normalize_llamaparse_pages_preserves_reliable_page_number() -> None:
    pages = [
        LlamaParsePage(
            page_number=5,
            markdown="نص الصفحة الخامسة",
        )
    ]

    result = normalize_llamaparse_pages(
        pages,
        preserve_page_numbers=True,
    )

    assert result == [
        NormalizedDocument(
            text="نص الصفحة الخامسة",
            page=5,
            section=None,
        )
    ]


def test_normalize_llamaparse_pages_can_drop_unreliable_page_number() -> None:
    pages = [
        LlamaParsePage(
            page_number=1,
            markdown="نص بلا صفحات حقيقية",
        )
    ]

    result = normalize_llamaparse_pages(
        pages,
        preserve_page_numbers=False,
    )

    assert result == [
        NormalizedDocument(
            text="نص بلا صفحات حقيقية",
            page=None,
            section=None,
        )
    ]
