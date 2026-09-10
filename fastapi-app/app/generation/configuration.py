from app.core.config import Settings
from app.generation.base import GenerationOptions


def generation_options_from_settings(
    settings: Settings,
) -> GenerationOptions:
    return GenerationOptions(
        temperature=settings.rag_generation_temperature,
    )
