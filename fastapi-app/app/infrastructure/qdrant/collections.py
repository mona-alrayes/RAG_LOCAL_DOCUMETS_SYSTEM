from qdrant_client import QdrantClient

from app.infrastructure.qdrant.schema import (
    DENSE_VECTOR_DISTANCE,
    DENSE_VECTOR_NAME,
    DENSE_VECTOR_SIZE,
    PAYLOAD_INDEX_SCHEMA,
    SPARSE_VECTOR_MODIFIER,
    SPARSE_VECTOR_NAME,
    build_dense_vector_name_config,
    build_dense_vector_params,
    build_sparse_vector_name_config,
    build_sparse_vector_params,
)


def ensure_collection_exists(
    client: QdrantClient,
    collection_name: str,
) -> None:
    if not client.collection_exists(collection_name=collection_name):
        client.create_collection(
            collection_name=collection_name,
            vectors_config={
                DENSE_VECTOR_NAME: build_dense_vector_params(),
            },
            sparse_vectors_config={
                SPARSE_VECTOR_NAME: build_sparse_vector_params(),
            },
        )
    else:
        _ensure_collection_vector_schema(
            client=client,
            collection_name=collection_name,
        )

    _ensure_payload_indexes(
        client=client,
        collection_name=collection_name,
    )


def _ensure_collection_vector_schema(
    client: QdrantClient,
    collection_name: str,
) -> None:
    collection_info = client.get_collection(
        collection_name=collection_name,
    )

    dense_vectors = collection_info.config.params.vectors
    sparse_vectors = collection_info.config.params.sparse_vectors or {}

    if not isinstance(dense_vectors, dict):
        raise RuntimeError(
            f"Qdrant collection '{collection_name}' uses an unnamed dense "
            "vector schema, but named vectors are required."
        )

    dense_vector = dense_vectors.get(DENSE_VECTOR_NAME)
    sparse_vector = sparse_vectors.get(SPARSE_VECTOR_NAME)

    if dense_vector is not None:
        if (
            dense_vector.size != DENSE_VECTOR_SIZE
            or dense_vector.distance != DENSE_VECTOR_DISTANCE
        ):
            raise RuntimeError(
                f"Qdrant collection '{collection_name}' has an incompatible "
                f"'{DENSE_VECTOR_NAME}' schema."
            )

    if sparse_vector is not None:
        if sparse_vector.modifier != SPARSE_VECTOR_MODIFIER:
            raise RuntimeError(
                f"Qdrant collection '{collection_name}' has an incompatible "
                f"'{SPARSE_VECTOR_NAME}' schema."
            )

    if dense_vector is None:
        client.create_vector_name(
            collection_name=collection_name,
            vector_name=DENSE_VECTOR_NAME,
            vector_name_config=build_dense_vector_name_config(),
        )

    if sparse_vector is None:
        client.create_vector_name(
            collection_name=collection_name,
            vector_name=SPARSE_VECTOR_NAME,
            vector_name_config=build_sparse_vector_name_config(),
        )


def _ensure_payload_indexes(
    client: QdrantClient,
    collection_name: str,
) -> None:
    collection_info = client.get_collection(
        collection_name=collection_name,
    )

    payload_schema = collection_info.payload_schema or {}

    for field_name, expected_schema in PAYLOAD_INDEX_SCHEMA.items():
        existing_index = payload_schema.get(field_name)

        if existing_index is not None:
            if existing_index.data_type != expected_schema:
                raise RuntimeError(
                    f"Qdrant collection '{collection_name}' has an incompatible "
                    f"payload index for '{field_name}': expected "
                    f"'{expected_schema.value}', got "
                    f"'{existing_index.data_type.value}'."
                )

            continue

        client.create_payload_index(
            collection_name=collection_name,
            field_name=field_name,
            field_schema=expected_schema,
            wait=True,
        )

