from qdrant_client import QdrantClient

from app.core.config import Settings


def build_qdrant_client(settings: Settings) -> QdrantClient:
    return QdrantClient(url=settings.qdrant_url)
