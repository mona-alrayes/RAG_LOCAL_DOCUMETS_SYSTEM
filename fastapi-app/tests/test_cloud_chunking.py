from app.core.config import Settings
from app.parsing.normalized import NormalizedDocument
from app.processing.cloud_chunking import CloudChunker


def test_cloud_chunker_keeps_short_document_as_one_chunk_with_metadata():
    document = NormalizedDocument(
        text="This is a short document.",
        page=3,
        section="Introduction",
    )

    chunker = CloudChunker(
        chunk_size=800,
        chunk_overlap=80,
    )

    chunks = chunker.chunk([document])

    assert len(chunks) == 1
    assert chunks[0].text == document.text
    assert chunks[0].page == 3
    assert chunks[0].section == "Introduction"


def test_cloud_chunker_splits_long_document_and_preserves_metadata():
    document = NormalizedDocument(
        text=("Alpha beta gamma delta epsilon. " * 30).strip(),
        page=7,
        section="Results",
    )

    chunker = CloudChunker(
        chunk_size=20,
        chunk_overlap=2,
    )

    chunks = chunker.chunk([document])

    assert len(chunks) > 1
    assert all(chunk.text.strip() for chunk in chunks)
    assert all(chunk.page == 7 for chunk in chunks)
    assert all(chunk.section == "Results" for chunk in chunks)


def test_chunking_settings_default_to_800_with_80_overlap(monkeypatch):
    monkeypatch.delenv("CHUNK_SIZE", raising=False)
    monkeypatch.delenv("CHUNK_OVERLAP", raising=False)

    settings = Settings(_env_file=None)

    assert settings.chunk_size == 800
    assert settings.chunk_overlap == 80


def test_chunking_settings_can_be_overridden_from_environment(monkeypatch):
    monkeypatch.setenv("CHUNK_SIZE", "600")
    monkeypatch.setenv("CHUNK_OVERLAP", "60")

    settings = Settings(_env_file=None)

    assert settings.chunk_size == 600
    assert settings.chunk_overlap == 60

def test_cloud_chunker_skips_blank_documents():
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

    chunker = CloudChunker(
        chunk_size=800,
        chunk_overlap=80,
    )

    chunks = chunker.chunk(documents)

    assert len(chunks) == 1
    assert chunks[0].text == "Valid content."
    assert chunks[0].page == 1
