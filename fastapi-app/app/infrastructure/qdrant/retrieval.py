import math
from typing import Any, Protocol
from uuid import UUID

from qdrant_client import QdrantClient, models

from app.core.exceptions import ApplicationException
from app.infrastructure.qdrant.schema import (
    DENSE_VECTOR_NAME,
    SPARSE_VECTOR_NAME,
)
from app.processing.base import ProcessingProfile
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
    CloudRetrievalTarget,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
    HybridLocalRetrievalTarget,
)
from app.services.retrieval_scope import RetrievalScope

RETRIEVAL_PAYLOAD_FIELDS = [
    "user_id",
    "document_id",
    "processing_run_id",
    "processing_profile",
    "chunk_index",
    "text",
    "page",
    "section",
    "source",
]


SparseQuery = (
    models.Document
    | models.SparseVector
)


class SparseQueryRepresenter(Protocol):
    def represent_query(
        self,
        question: str,
    ) -> SparseQuery:
        ...


def _scope_values(
    scope: RetrievalScope,
) -> dict[str, int | str]:
    return {
        "user_id": scope.user_id,
        "document_id": scope.document_id,
        "processing_run_id": scope.processing_run_id,
        "processing_profile": (
            scope.processing_profile.value
        ),
    }


def _build_scope_filter(
    *,
    scope: RetrievalScope,
) -> models.Filter:
    return models.Filter(
        must=[
            models.FieldCondition(
                key=key,
                match=models.MatchValue(
                    value=value,
                ),
            )
            for key, value
            in _scope_values(scope).items()
        ]
    )


def _validate_scope(
    *,
    payload: dict[str, Any],
    scope: RetrievalScope,
    error_code: str,
    error_message: str,
) -> None:
    expected_scope = _scope_values(scope)

    for key, expected_value in expected_scope.items():
        value = payload.get(key)

        if (
            type(value) is not type(expected_value)
            or value != expected_value
        ):
            raise ApplicationException(
                code=error_code,
                message=error_message,
            )


def _result_values(
    *,
    point: Any,
    payload: dict[str, Any],
    invalid_code: str,
    invalid_message: str,
) -> dict[str, Any]:
    try:
        point_id = point.id
        score = point.score
        document_id = payload["document_id"]
        processing_run_id = payload[
            "processing_run_id"
        ]
        processing_profile = payload[
            "processing_profile"
        ]
        chunk_index = payload["chunk_index"]
        text = payload["text"]
        page = payload["page"]
        section = payload["section"]
        source = payload["source"]
    except (AttributeError, KeyError) as exc:
        raise ApplicationException(
            code=invalid_code,
            message=invalid_message,
        ) from exc

    if (
        isinstance(point_id, bool)
        or not isinstance(
            point_id,
            (str, int, UUID),
        )
        or (
            isinstance(point_id, int)
            and point_id < 0
        )
        or not str(point_id).strip()
        or isinstance(score, bool)
        or not isinstance(
            score,
            (int, float),
        )
        or not math.isfinite(float(score))
        or type(document_id) is not int
        or document_id < 1
        or type(processing_run_id) is not int
        or processing_run_id < 1
        or type(processing_profile) is not str
        or type(chunk_index) is not int
        or chunk_index < 0
        or type(text) is not str
        or not text.strip()
        or (
            page is not None
            and (
                type(page) is not int
                or page < 1
            )
        )
        or (
            section is not None
            and type(section) is not str
        )
        or type(source) is not str
        or not source.strip()
    ):
        raise ApplicationException(
            code=invalid_code,
            message=invalid_message,
        )

    try:
        profile = ProcessingProfile(
            processing_profile
        )
    except ValueError as exc:
        raise ApplicationException(
            code=invalid_code,
            message=invalid_message,
        ) from exc

    return {
        "point_id": str(point_id),
        "retrieval_score": float(score),
        "document_id": document_id,
        "processing_run_id": (
            processing_run_id
        ),
        "processing_profile": profile,
        "chunk_index": chunk_index,
        "text": text,
        "page": page,
        "section": section,
        "source": source,
    }


def _query_rrf_points(
    *,
    client: QdrantClient,
    collection_name: str,
    scope: RetrievalScope,
    dense_query: list[float],
    sparse_query: SparseQuery,
    limit: int,
    candidate_multiplier: int,
) -> list[Any]:
    scope_filter = _build_scope_filter(
        scope=scope,
    )

    candidate_limit = (
        limit * candidate_multiplier
    )

    response = client.query_points(
        collection_name=collection_name,
        prefetch=[
            models.Prefetch(
                query=dense_query,
                using=DENSE_VECTOR_NAME,
                filter=scope_filter,
                limit=candidate_limit,
            ),
            models.Prefetch(
                query=sparse_query,
                using=SPARSE_VECTOR_NAME,
                filter=scope_filter,
                limit=candidate_limit,
            ),
        ],
        query=models.FusionQuery(
            fusion=models.Fusion.RRF,
        ),
        query_filter=scope_filter,
        limit=limit,
        with_payload=RETRIEVAL_PAYLOAD_FIELDS,
        with_vectors=False,
    )

    return list(response.points)


def _map_cloud_result(
    *,
    point: Any,
    scope: RetrievalScope,
) -> CloudRetrievalResult:
    payload = point.payload

    if not isinstance(payload, dict):
        raise ApplicationException(
            code="cloud_retrieval_result_invalid",
            message=(
                "Cloud retrieval result payload "
                "is invalid."
            ),
        )

    _validate_scope(
        payload=payload,
        scope=scope,
        error_code=(
            "cloud_retrieval_result_scope_invalid"
        ),
        error_message=(
            "Cloud retrieval result does not belong "
            "to the trusted retrieval scope."
        ),
    )

    values = _result_values(
        point=point,
        payload=payload,
        invalid_code=(
            "cloud_retrieval_result_invalid"
        ),
        invalid_message=(
            "Cloud retrieval result payload "
            "is invalid."
        ),
    )

    return CloudRetrievalResult(**values)


def _map_hybrid_local_result(
    *,
    point: Any,
    scope: RetrievalScope,
) -> HybridLocalRetrievalResult:
    payload = point.payload

    if not isinstance(payload, dict):
        raise ApplicationException(
            code=(
                "hybrid_local_retrieval_result_invalid"
            ),
            message=(
                "Hybrid Local retrieval result "
                "payload is invalid."
            ),
        )

    _validate_scope(
        payload=payload,
        scope=scope,
        error_code=(
            "hybrid_local_retrieval_result_"
            "scope_invalid"
        ),
        error_message=(
            "Hybrid Local retrieval result "
            "does not belong to the trusted "
            "retrieval scope."
        ),
    )

    values = _result_values(
        point=point,
        payload=payload,
        invalid_code=(
            "hybrid_local_retrieval_result_invalid"
        ),
        invalid_message=(
            "Hybrid Local retrieval result "
            "payload is invalid."
        ),
    )

    return HybridLocalRetrievalResult(
        **values
    )


class QdrantCloudDenseRetriever:
    def __init__(
        self,
        *,
        client: QdrantClient,
    ) -> None:
        self._client = client

    def retrieve(
        self,
        *,
        collection_name: str,
        user_id: int,
        target: CloudRetrievalTarget,
        query_vector: list[float],
        limit: int,
        question: str | None = None,
    ) -> list[CloudRetrievalResult]:
        scope = RetrievalScope(
            user_id=user_id,
            document_id=target.document_id,
            processing_run_id=(
                target.processing_run_id
            ),
            processing_profile=(
                target.processing_profile
            ),
        )

        query_filter = _build_scope_filter(
            scope=scope,
        )

        response = self._client.query_points(
            collection_name=collection_name,
            query=query_vector,
            using=DENSE_VECTOR_NAME,
            query_filter=query_filter,
            limit=limit,
            with_payload=RETRIEVAL_PAYLOAD_FIELDS,
            with_vectors=False,
        )

        return [
            _map_cloud_result(
                point=point,
                scope=scope,
            )
            for point in response.points
        ]


class QdrantCloudRrfRetriever:
    def __init__(
        self,
        *,
        client: QdrantClient,
        sparse_query_representer: (
            SparseQueryRepresenter
        ),
        candidate_multiplier: int,
    ) -> None:
        self._client = client
        self._sparse_query_representer = (
            sparse_query_representer
        )
        self._candidate_multiplier = (
            candidate_multiplier
        )

    def retrieve(
        self,
        *,
        collection_name: str,
        user_id: int,
        target: CloudRetrievalTarget,
        question: str,
        query_vector: list[float],
        limit: int,
        dense_only: bool = False,
    ) -> list[CloudRetrievalResult]:
        if dense_only:
            return QdrantCloudDenseRetriever(client=self._client).retrieve(
                collection_name=collection_name, user_id=user_id, target=target,
                query_vector=query_vector, limit=limit, question=question,
            )

        scope = RetrievalScope(
            user_id=user_id,
            document_id=target.document_id,
            processing_run_id=(
                target.processing_run_id
            ),
            processing_profile=(
                target.processing_profile
            ),
        )

        sparse_query = (
            self._sparse_query_representer
            .represent_query(question)
        )

        points = _query_rrf_points(
            client=self._client,
            collection_name=collection_name,
            scope=scope,
            dense_query=query_vector,
            sparse_query=sparse_query,
            limit=limit,
            candidate_multiplier=(
                self._candidate_multiplier
            ),
        )

        return [
            _map_cloud_result(
                point=point,
                scope=scope,
            )
            for point in points
        ]


class QdrantHybridLocalDenseRetriever:
    def __init__(
        self,
        *,
        client: QdrantClient,
    ) -> None:
        self._client = client

    def retrieve(
        self,
        *,
        collection_name: str,
        user_id: int,
        target: HybridLocalRetrievalTarget,
        query_vector: list[float],
        limit: int,
        question: str | None = None,
    ) -> list[HybridLocalRetrievalResult]:
        scope = RetrievalScope(
            user_id=user_id,
            document_id=target.document_id,
            processing_run_id=(
                target.processing_run_id
            ),
            processing_profile=(
                target.processing_profile
            ),
        )

        query_filter = _build_scope_filter(
            scope=scope,
        )

        response = self._client.query_points(
            collection_name=collection_name,
            query=query_vector,
            using=DENSE_VECTOR_NAME,
            query_filter=query_filter,
            limit=limit,
            with_payload=RETRIEVAL_PAYLOAD_FIELDS,
            with_vectors=False,
        )

        return [
            _map_hybrid_local_result(
                point=point,
                scope=scope,
            )
            for point in response.points
        ]


class QdrantHybridLocalRrfRetriever:
    def __init__(
        self,
        *,
        client: QdrantClient,
        sparse_query_representer: (
            SparseQueryRepresenter
        ),
        candidate_multiplier: int,
    ) -> None:
        self._client = client
        self._sparse_query_representer = (
            sparse_query_representer
        )
        self._candidate_multiplier = (
            candidate_multiplier
        )

    def retrieve(
        self,
        *,
        collection_name: str,
        user_id: int,
        target: HybridLocalRetrievalTarget,
        question: str,
        query_vector: list[float],
        limit: int,
        dense_only: bool = False,
    ) -> list[HybridLocalRetrievalResult]:
        if dense_only:
            return QdrantHybridLocalDenseRetriever(client=self._client).retrieve(
                collection_name=collection_name, user_id=user_id, target=target,
                query_vector=query_vector, limit=limit, question=question,
            )

        scope = RetrievalScope(
            user_id=user_id,
            document_id=target.document_id,
            processing_run_id=(
                target.processing_run_id
            ),
            processing_profile=(
                target.processing_profile
            ),
        )

        sparse_query = (
            self._sparse_query_representer
            .represent_query(question)
        )

        points = _query_rrf_points(
            client=self._client,
            collection_name=collection_name,
            scope=scope,
            dense_query=query_vector,
            sparse_query=sparse_query,
            limit=limit,
            candidate_multiplier=(
                self._candidate_multiplier
            ),
        )

        return [
            _map_hybrid_local_result(
                point=point,
                scope=scope,
            )
            for point in points
        ]
