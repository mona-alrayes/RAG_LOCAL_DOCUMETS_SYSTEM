from enum import StrEnum


class RetrievalPipeline(StrEnum):
    DENSE_ONLY = "dense_only"
    DENSE_SPARSE_RRF = "dense_sparse_rrf"
    FULL = "dense_sparse_rrf_reranker"
