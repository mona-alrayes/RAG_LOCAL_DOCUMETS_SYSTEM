from app.core.config import Settings
from app.infrastructure.qdrant.client import build_qdrant_client
from app.infrastructure.qdrant.collections import ensure_collection_exists


def initialize_qdrant(settings: Settings) -> None:
    client = build_qdrant_client(settings)

    try:
        for collection_name in (
            settings.qdrant_cloud_collection,
            settings.qdrant_hybrid_local_collection,
        ):
            ensure_collection_exists(
                client=client,
                collection_name=collection_name,
            )
    finally:
        client.close()
