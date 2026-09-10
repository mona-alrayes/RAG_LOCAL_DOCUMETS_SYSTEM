from qdrant_client import models


DENSE_VECTOR_NAME = "dense_vector"
DENSE_VECTOR_SIZE = 1024
DENSE_VECTOR_DISTANCE = models.Distance.COSINE

SPARSE_VECTOR_NAME = "bm25_sparse_vector"
SPARSE_VECTOR_MODIFIER = models.Modifier.IDF

PAYLOAD_INDEX_SCHEMA: dict[str, models.PayloadSchemaType] = {
    "chunk_index": models.PayloadSchemaType.INTEGER,
    "user_id": models.PayloadSchemaType.INTEGER,
    "document_id": models.PayloadSchemaType.INTEGER,
    "processing_run_id": models.PayloadSchemaType.INTEGER,
    "processing_profile": models.PayloadSchemaType.KEYWORD,
}


def build_dense_vector_params() -> models.VectorParams:
    return models.VectorParams(
        size=DENSE_VECTOR_SIZE,
        distance=DENSE_VECTOR_DISTANCE,
    )


def build_sparse_vector_params() -> models.SparseVectorParams:
    return models.SparseVectorParams(
        modifier=SPARSE_VECTOR_MODIFIER,
    )


def build_dense_vector_name_config() -> models.DenseVectorNameConfig:
    return models.DenseVectorNameConfig(
        dense=models.DenseVectorConfig(
            size=DENSE_VECTOR_SIZE,
            distance=DENSE_VECTOR_DISTANCE,
        ),
    )


def build_sparse_vector_name_config() -> models.SparseVectorNameConfig:
    return models.SparseVectorNameConfig(
        sparse=models.SparseVectorConfig(
            modifier=SPARSE_VECTOR_MODIFIER,
        ),
    )
