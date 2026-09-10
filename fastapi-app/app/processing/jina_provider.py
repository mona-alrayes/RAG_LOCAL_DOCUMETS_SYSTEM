import json
import math
from dataclasses import dataclass
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


JINA_EMBEDDINGS_URL = "https://api.jina.ai/v1/embeddings"
JINA_RERANK_URL = "https://api.jina.ai/v1/rerank"

RETRYABLE_STATUS_CODES = {
    429,
    500,
    502,
    503,
    504,
}


class JinaProviderError(Exception):
    def __init__(self, *, retryable: bool) -> None:
        super().__init__("Jina provider request failed.")
        self.retryable = retryable


@dataclass(frozen=True, slots=True)
class JinaRerankResult:
    index: int
    relevance_score: float


def _post_json(
    *,
    url: str,
    api_key: str,
    payload: dict[str, Any],
) -> dict[str, Any]:
    request = Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
        method="POST",
    )

    try:
        with urlopen(request) as response:
            body = json.loads(
                response.read().decode("utf-8")
            )
    except HTTPError as exc:
        raise JinaProviderError(
            retryable=(
                exc.code in RETRYABLE_STATUS_CODES
            ),
        ) from exc
    except (URLError, TimeoutError) as exc:
        raise JinaProviderError(
            retryable=True
        ) from exc
    except (
        json.JSONDecodeError,
        UnicodeDecodeError,
    ) as exc:
        raise JinaProviderError(
            retryable=False
        ) from exc

    if not isinstance(body, dict):
        raise JinaProviderError(
            retryable=False
        )

    return body


class JinaEmbeddingProvider:
    def __init__(
        self,
        *,
        api_key: str,
        model: str,
        task: str,
        dimensions: int,
    ) -> None:
        self._api_key = api_key
        self._model = model
        self._task = task
        self._dimensions = dimensions

    def embed(
        self,
        texts: list[str],
    ) -> list[list[float]]:
        body = _post_json(
            url=JINA_EMBEDDINGS_URL,
            api_key=self._api_key,
            payload={
                "input": texts,
                "model": self._model,
                "encoding_type": "float",
                "task": self._task,
                "dimensions": self._dimensions,
            },
        )

        try:
            embeddings = sorted(
                body["data"],
                key=lambda item: item["index"],
            )

            return [
                [
                    float(value)
                    for value in item["embedding"]
                ]
                for item in embeddings
            ]
        except (
            KeyError,
            TypeError,
            ValueError,
        ) as exc:
            raise JinaProviderError(
                retryable=False
            ) from exc


class JinaRerankerProvider:
    def __init__(
        self,
        *,
        api_key: str,
        model: str,
    ) -> None:
        self._api_key = api_key
        self._model = model

    def rerank(
        self,
        *,
        query: str,
        documents: list[str],
        top_n: int,
    ) -> list[JinaRerankResult]:
        body = _post_json(
            url=JINA_RERANK_URL,
            api_key=self._api_key,
            payload={
                "model": self._model,
                "query": query,
                "documents": documents,
                "top_n": top_n,
                "return_documents": False,
            },
        )

        try:
            raw_results = body["results"]

            if not isinstance(
                raw_results,
                list,
            ):
                raise TypeError

            results: list[
                JinaRerankResult
            ] = []

            for item in raw_results:
                if not isinstance(item, dict):
                    raise TypeError

                index = item["index"]
                score = item[
                    "relevance_score"
                ]

                if (
                    isinstance(index, bool)
                    or not isinstance(index, int)
                ):
                    raise TypeError

                if (
                    isinstance(score, bool)
                    or not isinstance(
                        score,
                        (int, float),
                    )
                ):
                    raise TypeError

                score_value = float(score)

                if not math.isfinite(
                    score_value
                ):
                    raise ValueError

                results.append(
                    JinaRerankResult(
                        index=index,
                        relevance_score=(
                            score_value
                        ),
                    )
                )

            return results
        except (
            KeyError,
            TypeError,
            ValueError,
        ) as exc:
            raise JinaProviderError(
                retryable=False
            ) from exc
