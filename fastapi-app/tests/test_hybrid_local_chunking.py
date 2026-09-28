from app.core.config import Settings
from app.parsing.normalized import NormalizedDocument
from app.processing.hybrid_local_chunking import HybridLocalChunker


def test_hybrid_local_chunker_keeps_short_document_as_one_chunk_with_metadata():
    settings = Settings(_env_file=None)
    document = NormalizedDocument(
        text="This is a short document.",
        page=3,
        section="Introduction",
    )

    chunker = HybridLocalChunker(
        chunk_size=settings.chunk_size,
        chunk_overlap=settings.chunk_overlap,
    )

    chunks = chunker.chunk([document])

    assert len(chunks) == 1
    assert chunks[0].text == document.text
    assert chunks[0].page == 3
    assert chunks[0].section == "Introduction"


def test_hybrid_local_chunker_splits_long_document_and_preserves_metadata():
    document = NormalizedDocument(
        text=("Alpha beta gamma delta epsilon. " * 30).strip(),
        page=7,
        section="Results",
    )

    chunker = HybridLocalChunker(
        chunk_size=20,
        chunk_overlap=2,
    )

    chunks = chunker.chunk([document])

    assert len(chunks) > 1
    assert all(chunk.text.strip() for chunk in chunks)
    assert all(chunk.page == 7 for chunk in chunks)
    assert all(chunk.section == "Results" for chunk in chunks)


def test_hybrid_local_chunking_uses_800_80_baseline_settings(monkeypatch):
    monkeypatch.delenv("CHUNK_SIZE", raising=False)
    monkeypatch.delenv("CHUNK_OVERLAP", raising=False)

    settings = Settings(_env_file=None)

    assert settings.chunk_size == 800
    assert settings.chunk_overlap == 80

def test_hybrid_local_chunker_skips_blank_documents():
    documents = [
        NormalizedDocument(
            text="Valid content.",
            page=1,
        ),
        NormalizedDocument(
            text="",
            page=2,
        ),
        NormalizedDocument(
            text="   \n\t",
            page=3,
        ),
    ]

    chunker = HybridLocalChunker(
        chunk_size=800,
        chunk_overlap=80,
    )

    chunks = chunker.chunk(documents)

    assert len(chunks) == 1
    assert chunks[0].text == "Valid content."
    assert chunks[0].page == 1
