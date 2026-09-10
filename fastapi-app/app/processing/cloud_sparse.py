from qdrant_client import models

from app.processing.chunks import NormalizedChunk


CLOUD_SPARSE_MODEL = "Qdrant/bm25"
CLOUD_SPARSE_TOKENIZER = "multilingual"


class CloudSparseRepresenter:
    def represent(
        self,
        chunks: list[NormalizedChunk],
    ) -> list[models.Document]:
        return [
            self._document(chunk.text)
            for chunk in chunks
        ]

    def represent_query(
        self,
        question: str,
    ) -> models.Document:
        return self._document(question.strip())

    @staticmethod
    def _document(
        text: str,
    ) -> models.Document:
        return models.Document(
            text=text,
            model=CLOUD_SPARSE_MODEL,
            options={
                "tokenizer": CLOUD_SPARSE_TOKENIZER,
            },
        )
