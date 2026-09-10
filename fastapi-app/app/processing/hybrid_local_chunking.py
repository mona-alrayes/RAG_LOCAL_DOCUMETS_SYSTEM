from llama_index.core.node_parser import SentenceSplitter

from app.parsing.normalized import NormalizedDocument
from app.processing.chunks import NormalizedChunk


class HybridLocalChunker:
    def __init__(self, chunk_size: int, chunk_overlap: int) -> None:
        self._splitter = SentenceSplitter(
            chunk_size=chunk_size,
            chunk_overlap=chunk_overlap,
        )

    def chunk(
        self,
        documents: list[NormalizedDocument],
    ) -> list[NormalizedChunk]:
        chunks: list[NormalizedChunk] = []

        for document in documents:
            chunks.extend(
                NormalizedChunk(
                    text=text,
                    page=document.page,
                    section=document.section,
                )
                for text in self._splitter.split_text(document.text)
                if text.strip()
            )

        return chunks