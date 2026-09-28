from itertools import pairwise

import pytest
from llama_index.core.utils import get_tokenizer

from app.parsing.normalized import NormalizedDocument
from app.processing.cloud_chunking import CloudChunker
from app.processing.hybrid_local_chunking import HybridLocalChunker


@pytest.fixture(params=[CloudChunker, HybridLocalChunker])
def chunker(request):
    # نفس الاختبارات للمسارين لضمان تطابق سلوك التقطيع.
    return request.param(chunk_size=120, chunk_overlap=12)


def assert_bounded(chunks, size=120):
    assert chunks
    assert all(len(get_tokenizer()(chunk.text)) <= size for chunk in chunks)


@pytest.mark.parametrize("chunker_class", [CloudChunker, HybridLocalChunker])
def test_chunker_rejects_size_below_internal_minimum(chunker_class):
    with pytest.raises(ValueError, match="chunk_size must be at least 8"):
        chunker_class(chunk_size=7, chunk_overlap=2)


def test_headings_keep_sections_separate_with_page_and_path(chunker):
    chunks = chunker.chunk(
        [
            NormalizedDocument(
                text="# Guide\n\nOverview.\n\n## Setup\n\nInstall package.\n\n## Usage\n\nRun command.",
                page=4,
            )
        ]
    )
    assert len(chunks) == 3
    assert chunks[1].section == "Guide / Setup"
    assert "Install package." in chunks[1].text
    assert "Run command." not in chunks[1].text
    assert all(chunk.page == 4 for chunk in chunks)


def test_short_grant_list_stays_with_its_condition(chunker):
    # مثال عام: يجب ألا تنفصل قيمة المنحة عن الحالات التي تخصها.
    text = "## Grants\n\nFour salaries apply to:\n\n1. Retirement.\n\n2. Illness.\n\n3. Position cancellation."
    chunks = chunker.chunk([NormalizedDocument(text=text, page=65)])
    assert len(chunks) == 1
    assert "Four salaries" in chunks[0].text
    assert "Position cancellation" in chunks[0].text


def test_long_list_repeats_heading_and_condition_without_losing_items(chunker):
    items = [f"{i}. Item-{i} eligible for the annual benefit." for i in range(1, 31)]
    text = "## Benefits\n\nOnly for permanent staff:\n\n" + "\n\n".join(items)
    chunks = chunker.chunk([NormalizedDocument(text=text, page=2)])
    assert len(chunks) > 1
    assert_bounded(chunks)
    for item in items:
        assert any(item in chunk.text for chunk in chunks)
    for chunk in chunks:
        assert "## Benefits" in chunk.text
        assert "Only for permanent staff:" in chunk.text


def test_large_table_repeats_column_names_without_losing_rows(chunker):
    header = "| Product | Price USD |\n| --- | --- |"
    rows = [f"| Widget-{i} | {i + 100} |" for i in range(35)]
    chunks = chunker.chunk(
        [
            NormalizedDocument(
                text="## Prices\n\n" + header + "\n" + "\n".join(rows), page=8
            )
        ]
    )
    assert len(chunks) > 1
    assert_bounded(chunks)
    assert all(header in chunk.text and "## Prices" in chunk.text for chunk in chunks)
    for row in rows:
        assert any(row in chunk.text for chunk in chunks)


def test_fenced_code_keeps_hashes_and_blank_lines(chunker):
    text = "## Example\n\n```python\n# Not a section\n\nprint('hello')\n```"
    chunks = chunker.chunk([NormalizedDocument(text=text)])
    assert len(chunks) == 1
    assert "# Not a section\n\nprint('hello')" in chunks[0].text


def test_long_prose_and_oversized_heading_remain_bounded(chunker):
    for text in [
        "One ordinary sentence. " * 200,
        "# " + "long " * 300 + "\n\nBody at end.",
    ]:
        chunks = chunker.chunk([NormalizedDocument(text=text, section="Original")])
        assert_bounded(chunks)
        assert (
            "Body at end." in chunks[-1].text
            if text.startswith("#")
            else "sentence." in chunks[-1].text
        )


def test_pages_are_not_merged_even_when_short(chunker):
    chunks = chunker.chunk(
        [
            NormalizedDocument(text="First page", page=1),
            NormalizedDocument(text="Second page", page=2),
        ]
    )
    assert [(chunk.text, chunk.page) for chunk in chunks] == [
        ("First page", 1),
        ("Second page", 2),
    ]


def test_tilde_fences_do_not_turn_code_comments_into_sections(chunker):
    # تعليق الكود الذي يبدأ بـ # ليس عنواناً في الوثيقة.
    text = "## Example\n\n~~~python\n# code comment\n\nprint('ok')\n~~~"
    chunks = chunker.chunk([NormalizedDocument(text=text)])
    assert len(chunks) == 1
    assert "~~~python\n# code comment\n\nprint('ok')\n~~~" in chunks[0].text


def test_tilde_fence_does_not_disable_section_boundaries(chunker):
    text = (
        "## First\n\n"
        "First-only bridge sentence.\n\n"
        "~~~python\n# code comment\nprint('ok')\n~~~\n\n"
        "## Second\n\n"
        "Second-only body."
    )

    chunks = chunker.chunk([NormalizedDocument(text=text, page=5)])

    assert len(chunks) == 2
    assert "~~~python\n# code comment\nprint('ok')\n~~~" in chunks[0].text
    assert chunks[1].text.startswith("## Second")
    assert "First-only bridge sentence." not in chunks[1].text
    assert chunks[1].section == "Second"


@pytest.mark.parametrize(
    "fenced_code",
    [
        "~~~~python\n~~~~not-a-close\n# still code\n~~~~",
        "````python\n```\n# still code\n````",
    ],
)
def test_nested_fence_markers_do_not_change_heading_boundaries(
    chunker,
    fenced_code,
):
    text = (
        "## First\n\n"
        "First section.\n\n"
        f"{fenced_code}\n\n"
        "## Second\n\n"
        "Second section."
    )

    chunks = chunker.chunk([NormalizedDocument(text=text, page=6)])

    assert len(chunks) == 2
    assert "# still code" in chunks[0].text
    assert chunks[0].section == "First"
    assert chunks[1].text.startswith("## Second")
    assert chunks[1].section == "Second"


@pytest.mark.parametrize(
    ("opening", "closing"),
    [("```python", "````"), ("~~~python", "~~~~")],
)
def test_long_fenced_block_keeps_a_longer_valid_closer(
    chunker,
    opening,
    closing,
):
    code = "\n".join(f"print('line-{index}')" for index in range(80))
    text = f"## Example\n\n{opening}\n{code}\n{closing}"

    chunks = chunker.chunk([NormalizedDocument(text=text, page=7)])

    assert len(chunks) > 1
    assert_bounded(chunks)
    assert all(opening in chunk.text for chunk in chunks)
    assert all(chunk.text.rstrip().endswith(closing) for chunk in chunks)


def test_four_space_indented_backticks_are_not_a_fence_boundary(chunker):
    text = (
        "## First\n\n"
        "    ```not-a-fence\n"
        "    indented code\n\n"
        "## Second\n\n"
        "Second section."
    )

    chunks = chunker.chunk([NormalizedDocument(text=text, page=8)])

    assert len(chunks) == 2
    assert chunks[1].text.startswith("## Second")
    assert chunks[1].section == "Second"


def test_short_list_keeps_its_intro_when_preceding_prose_fills_chunk(chunker):
    text = (
        "## Benefits\n\n"
        + "Background sentence. " * 25
        + "\n\nOnly permanent staff:\n\n1. Benefit one.\n2. Benefit two."
    )
    chunks = chunker.chunk([NormalizedDocument(text=text)])
    assert_bounded(chunks)
    for chunk in chunks:
        if "1. Benefit one." in chunk.text:
            assert "Only permanent staff:" in chunk.text


def test_list_without_blank_line_keeps_intro_when_split(chunker):
    # استخراج Markdown ليس دائماً منسقاً بسطور فارغة بين الكتل.
    text = "## Benefits\nOnly permanent staff:\n" + "\n".join(
        f"{i}. Benefit-{i} available throughout the year." for i in range(30)
    )
    chunks = chunker.chunk([NormalizedDocument(text=text)])
    assert_bounded(chunks)
    assert all("Only permanent staff:" in chunk.text for chunk in chunks)


def test_table_without_blank_line_repeats_headers(chunker):
    text = "## Prices\nCurrent prices:\n| Name | USD |\n| --- | --- |\n" + "\n".join(
        f"| Product-{i} | {100 + i} |" for i in range(30)
    )
    chunks = chunker.chunk([NormalizedDocument(text=text)])
    assert_bounded(chunks)
    assert all(
        "| Name | USD |" in chunk.text for chunk in chunks if "| Product-" in chunk.text
    )


def test_short_structural_chunks_do_not_overlap_across_sections(chunker):
    chunks = chunker.chunk(
        [
            NormalizedDocument(
                text=(
                    "## First topic\n\n"
                    "Independent background. Closing bridge sentence.\n\n"
                    "## Second topic\n\n"
                    "Dependent explanation continues here."
                ),
                page=9,
            )
        ]
    )

    assert len(chunks) == 2
    assert "Closing bridge sentence." in chunks[0].text
    assert "Closing bridge sentence." not in chunks[1].text
    assert chunks[1].text.startswith("## Second topic")
    assert_bounded(chunks)


def test_section_that_fits_configured_size_is_not_split_to_reserve_overlap(
    chunker,
):
    text = "## Topic\n\n" + "word " * 105

    assert 108 == len(get_tokenizer()(text.strip()))

    chunks = chunker.chunk([NormalizedDocument(text=text, page=3)])

    assert len(chunks) == 1
    assert chunks[0].text == text.strip()
    assert_bounded(chunks)


def test_long_section_applies_overlap_between_its_own_parts(chunker):
    text = "## One topic\n\n" + "Independent numbered sentence. " * 80

    chunks = chunker.chunk([NormalizedDocument(text=text, page=9)])

    assert len(chunks) > 1
    assert_bounded(chunks)
    for previous, current in pairwise(chunks):
        previous_words = previous.text.split()
        current_words = current.text.split()
        assert any(
            previous_words[-count:] == current_words[:count]
            for count in range(1, min(len(previous_words), len(current_words)) + 1)
        )
