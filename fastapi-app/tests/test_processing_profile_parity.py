from dataclasses import dataclass

from app.core.config import Settings
from app.parsing.normalized import NormalizedDocument
from app.processing.base import ProcessingProfile
from app.processing.chunks import NormalizedChunk
from app.processing.cloud_chunking import CloudChunker
from app.processing.cloud_sparse import CloudSparseRepresenter
from app.processing.hybrid_local_chunking import HybridLocalChunker
from app.processing.local_sparse import LocalBm25Representer
from app.processing.reporting import (
    ProcessingReportBuilder,
    ProcessingStage,
    build_profile_snapshot,
)


@dataclass
class FakeSparseEmbedding:
    indices: list[int]
    values: list[float]


class FakeBm25Embedder:
    def embed(self, texts: list[str]):
        return iter(
            FakeSparseEmbedding(indices=[index], values=[1.0])
            for index, _ in enumerate(texts)
        )


def test_chunking_contract_is_consistent_across_profiles() -> None:
    documents = [
        NormalizedDocument(
            text="هذا نص قصير لاختبار عقد التقطيع.",
            page=3,
            section="introduction",
        )
    ]

    for chunker_class in (CloudChunker, HybridLocalChunker):
        chunks = chunker_class(
            chunk_size=800,
            chunk_overlap=80,
        ).chunk(documents)

        assert len(chunks) == 1
        assert isinstance(chunks[0], NormalizedChunk)
        assert chunks[0].text
        assert chunks[0].page == 3
        assert chunks[0].section == "introduction"


def test_sparse_representation_preserves_chunk_count_across_profiles() -> None:
    chunks = [
        NormalizedChunk(text="المقطع الأول", page=1),
        NormalizedChunk(text="المقطع الثاني", page=2),
    ]

    cloud_result = CloudSparseRepresenter().represent(chunks)
    local_result = LocalBm25Representer(
        embedder=FakeBm25Embedder()
    ).represent(chunks)

    assert len(cloud_result) == len(chunks)
    assert len(local_result) == len(chunks)


def test_profile_configuration_is_isolated() -> None:
    common = {
        "chunk_size": 800,
        "chunk_overlap": 80,
        "embed_batch_size": 6,
        "wait_between_batches": 3,
        "rate_limit_retry_wait": 30,
        "max_retries": 5,
    }

    cloud_before = build_profile_snapshot(
        profile=ProcessingProfile.CLOUD,
        settings=Settings(
            **common,
            cloud_embed_model="cloud-model",
            local_embed_model="local-model-a",
        ),
    )
    cloud_after_local_change = build_profile_snapshot(
        profile=ProcessingProfile.CLOUD,
        settings=Settings(
            **common,
            cloud_embed_model="cloud-model",
            local_embed_model="local-model-b",
        ),
    )

    assert cloud_before == cloud_after_local_change

    local_before = build_profile_snapshot(
        profile=ProcessingProfile.HYBRID_LOCAL,
        settings=Settings(
            **common,
            cloud_embed_model="cloud-model-a",
            local_embed_model="local-model",
        ),
    )
    local_after_cloud_change = build_profile_snapshot(
        profile=ProcessingProfile.HYBRID_LOCAL,
        settings=Settings(
            chunk_size=800,
            chunk_overlap=80,
            cloud_embed_model="cloud-model-b",
            local_embed_model="local-model",
            embed_batch_size=20,
            wait_between_batches=0,
            rate_limit_retry_wait=1,
            max_retries=1,
        ),
    )

    assert local_before == local_after_cloud_change

    assert cloud_before.dense_embedding.provider == "jina"
    assert local_before.dense_embedding.provider == "transformers"

    assert cloud_before.sparse_representation.provider == "qdrant"
    assert local_before.sparse_representation.provider == "fastembed"

    assert cloud_before.batching is not None
    assert local_before.batching is None


def test_processing_report_contract_is_consistent_across_profiles() -> None:
    settings = Settings(
        chunk_size=800,
        chunk_overlap=80,
        cloud_embed_model="cloud-model",
        local_embed_model="local-model",
    )

    documents = [
        NormalizedDocument(
            text="نص الوثيقة",
            page=1,
        )
    ]
    chunks = [
        NormalizedChunk(
            text="نص الوثيقة",
            page=1,
        )
    ]
    vectors = [[0.0] * 1024]
    timings = {ProcessingStage.TOTAL: 10}

    builder = ProcessingReportBuilder()

    cloud_report = builder.build(
        profile_snapshot=build_profile_snapshot(
            profile=ProcessingProfile.CLOUD,
            settings=settings,
        ),
        documents=documents,
        chunks=chunks,
        dense_vectors=vectors,
        stage_timings_ms=timings,
    )

    local_report = builder.build(
        profile_snapshot=build_profile_snapshot(
            profile=ProcessingProfile.HYBRID_LOCAL,
            settings=settings,
        ),
        documents=documents,
        chunks=chunks,
        dense_vectors=vectors,
        stage_timings_ms=timings,
    )

    assert set(cloud_report.model_dump()) == set(local_report.model_dump())

    assert cloud_report.total_pages == local_report.total_pages == 1
    assert cloud_report.total_chunks == local_report.total_chunks == 1
    assert cloud_report.vector_count == local_report.vector_count == 1
    assert cloud_report.vector_dimension == local_report.vector_dimension == 1024

    assert cloud_report.profile_snapshot.profile is ProcessingProfile.CLOUD
    assert (
        local_report.profile_snapshot.profile
        is ProcessingProfile.HYBRID_LOCAL
    )
