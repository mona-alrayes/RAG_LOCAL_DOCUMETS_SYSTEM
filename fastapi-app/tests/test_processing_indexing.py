from app.core.config import Settings
from app.processing.base import ProcessingProfile
from app.processing.indexing import resolve_qdrant_collection


def test_cloud_profile_resolves_cloud_collection() -> None:
    settings = Settings(
        qdrant_cloud_collection="rag_documents_cloud",
        qdrant_hybrid_local_collection="rag_documents_hybrid_local",
    )

    collection_name = resolve_qdrant_collection(
        profile=ProcessingProfile.CLOUD,
        settings=settings,
    )

    assert collection_name == "rag_documents_cloud"


def test_hybrid_local_profile_resolves_hybrid_local_collection() -> None:
    settings = Settings(
        qdrant_cloud_collection="rag_documents_cloud",
        qdrant_hybrid_local_collection="rag_documents_hybrid_local",
    )

    collection_name = resolve_qdrant_collection(
        profile=ProcessingProfile.HYBRID_LOCAL,
        settings=settings,
    )

    assert collection_name == "rag_documents_hybrid_local"
