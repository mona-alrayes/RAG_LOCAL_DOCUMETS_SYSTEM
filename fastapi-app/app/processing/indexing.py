from app.core.config import Settings
from app.core.exceptions import ApplicationException
from app.processing.base import ProcessingProfile


def resolve_qdrant_collection(
    *,
    profile: ProcessingProfile,
    settings: Settings,
) -> str:
    if profile is ProcessingProfile.CLOUD:
        return settings.qdrant_cloud_collection

    if profile is ProcessingProfile.HYBRID_LOCAL:
        return settings.qdrant_hybrid_local_collection

    raise ApplicationException(
        code="processing_profile_index_collection_unsupported",
        message="Processing profile has no configured Qdrant collection.",
    )
