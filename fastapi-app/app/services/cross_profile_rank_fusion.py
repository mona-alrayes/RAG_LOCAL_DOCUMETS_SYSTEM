from app.core.exceptions import ApplicationException
from app.processing.base import ProcessingProfile
from app.services.cloud_retrieval import (
    CloudRetrievalResult,
)
from app.services.hybrid_local_retrieval import (
    HybridLocalRetrievalResult,
)


CROSS_PROFILE_RRF_K = 60

RetrievalResult = (
    CloudRetrievalResult
    | HybridLocalRetrievalResult
)

CandidateIdentity = tuple[
    ProcessingProfile,
    int,
    int,
    str,
]


class CrossProfileRankFusionService:
    def fuse(
        self,
        *,
        ranked_result_collections: list[
            list[RetrievalResult]
        ],
        limit: int,
    ) -> list[RetrievalResult]:
        self._validate_limit(limit)

        if not isinstance(
            ranked_result_collections,
            list,
        ):
            self._raise_invalid_input()

        fused_candidates: list[
            tuple[
                float,
                int,
                int,
                RetrievalResult,
            ]
        ] = []

        seen_candidates: set[
            CandidateIdentity
        ] = set()

        for (
            collection_index,
            ranked_results,
        ) in enumerate(
            ranked_result_collections
        ):
            if not isinstance(
                ranked_results,
                list,
            ):
                self._raise_invalid_input()

            for rank, candidate in enumerate(
                ranked_results,
                start=1,
            ):
                self._validate_candidate(
                    candidate
                )

                identity = (
                    self._candidate_identity(
                        candidate
                    )
                )

                if identity in seen_candidates:
                    raise ApplicationException(
                        code=(
                            "cross_profile_rank_"
                            "fusion_candidate_duplicate"
                        ),
                        message=(
                            "Cross-profile rank "
                            "fusion candidate is "
                            "duplicated."
                        ),
                    )

                seen_candidates.add(identity)

                fusion_score = (
                    1.0
                    / (
                        CROSS_PROFILE_RRF_K
                        + rank
                    )
                )

                fused_candidates.append(
                    (
                        fusion_score,
                        collection_index,
                        rank,
                        candidate,
                    )
                )

        fused_candidates.sort(
            key=lambda item: (
                -item[0],
                item[1],
                item[2],
            )
        )

        return [
            item[3]
            for item in fused_candidates[:limit]
        ]

    @staticmethod
    def _validate_limit(limit: int) -> None:
        if (
            isinstance(limit, bool)
            or not isinstance(limit, int)
            or limit < 1
        ):
            raise ApplicationException(
                code=(
                    "cross_profile_rank_"
                    "fusion_limit_invalid"
                ),
                message=(
                    "Cross-profile rank fusion "
                    "limit must be a positive "
                    "integer."
                ),
            )

    @classmethod
    def _validate_candidate(
        cls,
        candidate: object,
    ) -> None:
        if isinstance(
            candidate,
            CloudRetrievalResult,
        ):
            expected_profile = (
                ProcessingProfile.CLOUD
            )
        elif isinstance(
            candidate,
            HybridLocalRetrievalResult,
        ):
            expected_profile = (
                ProcessingProfile.HYBRID_LOCAL
            )
        else:
            cls._raise_invalid_input()

        if (
            candidate.processing_profile
            is not expected_profile
        ):
            cls._raise_invalid_input()

        if (
            isinstance(candidate.document_id, bool)
            or not isinstance(
                candidate.document_id,
                int,
            )
            or candidate.document_id < 1
            or isinstance(
                candidate.processing_run_id,
                bool,
            )
            or not isinstance(
                candidate.processing_run_id,
                int,
            )
            or candidate.processing_run_id < 1
            or not isinstance(
                candidate.point_id,
                str,
            )
            or not candidate.point_id.strip()
        ):
            cls._raise_invalid_input()

    @staticmethod
    def _candidate_identity(
        candidate: RetrievalResult,
    ) -> CandidateIdentity:
        return (
            candidate.processing_profile,
            candidate.document_id,
            candidate.processing_run_id,
            candidate.point_id,
        )

    @staticmethod
    def _raise_invalid_input() -> None:
        raise ApplicationException(
            code=(
                "cross_profile_rank_"
                "fusion_input_invalid"
            ),
            message=(
                "Cross-profile rank fusion "
                "input is invalid."
            ),
        )
