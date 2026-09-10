from dataclasses import dataclass

from app.processing.base import ProcessingProfile


@dataclass(frozen=True, slots=True)
class RetrievalScope:
    user_id: int
    document_id: int
    processing_run_id: int
    processing_profile: ProcessingProfile
