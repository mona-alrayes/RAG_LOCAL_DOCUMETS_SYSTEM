from collections.abc import Iterable
from typing import Generic, TypeVar

from app.core.exceptions import ApplicationException
from app.processing.base import BaseProcessingProfile, ProcessingProfile


ProfileT = TypeVar(
    "ProfileT",
    bound=BaseProcessingProfile,
)


class ProcessingProfileRegistry(Generic[ProfileT]):
    def __init__(
        self,
        profiles: Iterable[ProfileT],
    ) -> None:
        self._profiles = {
            profile.profile: profile
            for profile in profiles
        }

    def resolve(
        self,
        profile: ProcessingProfile,
    ) -> ProfileT:
        if not isinstance(profile, ProcessingProfile):
            raise ApplicationException(
                code="invalid_processing_profile",
                message=(
                    "Processing profile must be a trusted "
                    "ProcessingProfile value."
                ),
            )

        try:
            return self._profiles[profile]
        except KeyError:
            raise ApplicationException(
                code="processing_profile_not_registered",
                message=(
                    f"Processing profile '{profile.value}' "
                    "is not registered."
                ),
            ) from None
