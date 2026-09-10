from typing import Protocol

from app.core.exceptions import ApplicationException
from app.processing.local_embeddings import LOCAL_EMBEDDING_DIMENSION


class LocalTextEmbedder(Protocol):
    def embed_texts(
        self,
        texts: list[str],
    ) -> list[list[float]]: ...


class LocalBgeM3QueryEmbedder:
    def __init__(
        self,
        *,
        text_embedder: LocalTextEmbedder,
    ) -> None:
        self._text_embedder = text_embedder

    def embed(
        self,
        question: str,
    ) -> list[float]:
        normalized_question = question.strip()

        if not normalized_question:
            raise ApplicationException(
                code="local_query_invalid",
                message="Hybrid Local retrieval question must not be blank.",
            )

        try:
            vectors = self._text_embedder.embed_texts(
                [normalized_question],
            )
        except ApplicationException:
            raise
        except Exception as exc:
            raise ApplicationException(
                code="local_query_embedding_failed",
                message="Hybrid Local query embedding failed.",
            ) from exc

        if (
            not isinstance(vectors, list)
            or len(vectors) != 1
            or not isinstance(vectors[0], list)
            or len(vectors[0]) != LOCAL_EMBEDDING_DIMENSION
        ):
            raise ApplicationException(
                code="local_query_embedding_result_invalid",
                message=(
                    "Hybrid Local query embedding result must contain "
                    f"exactly one {LOCAL_EMBEDDING_DIMENSION}-dimension vector."
                ),
            )

        try:
            return [
                float(value)
                for value in vectors[0]
            ]
        except (TypeError, ValueError) as exc:
            raise ApplicationException(
                code="local_query_embedding_result_invalid",
                message="Hybrid Local query embedding result is invalid.",
            ) from exc
