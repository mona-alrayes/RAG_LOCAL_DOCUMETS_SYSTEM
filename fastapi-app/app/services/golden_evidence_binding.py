import re
import unicodedata
from dataclasses import dataclass
from itertools import pairwise
from pathlib import PurePath

from qdrant_client import QdrantClient, models

from app.core.config import Settings
from app.infrastructure.qdrant.persistence import (
    PointScope,
    build_point_scope_filter,
)
from app.processing.indexing import resolve_qdrant_collection
from app.schemas.evaluation import (
    BoundChunk,
    EvidenceBinding,
    GoldenBindingResult,
    GoldenEvidence,
)
from app.schemas.rag import DocumentTarget

_ARABIC_DIACRITICS = re.compile(
    r"[\u0610-\u061a\u064b-\u065f\u0670\u06d6-\u06ed]"
)
_NON_WORD = re.compile(r"[^\w\u0600-\u06ff]+", re.UNICODE)
_SPACE = re.compile(r"\s+")


@dataclass(frozen=True, slots=True)
class _BindingOption:
    chunks: tuple[BoundChunk, ...]
    score: float


class GoldenEvidenceBinder:
    MIN_SCORE = 0.65
    AMBIGUITY_DELTA = 0.03

    def __init__(
        self,
        *,
        settings: Settings,
        client: QdrantClient,
    ) -> None:
        self.settings = settings
        self.client = client

    def bind(
        self,
        *,
        user_id: int,
        targets: list[DocumentTarget],
        evidence: list[GoldenEvidence],
        is_answerable: bool,
    ) -> GoldenBindingResult:
        if not is_answerable:
            return GoldenBindingResult(
                status="not_applicable",
                relevant_chunks=[],
                evidence=[],
            )

        chunks = self._read_chunks(
            user_id=user_id,
            targets=targets,
        )

        bindings = [
            self._bind_one(
                evidence_index=index,
                evidence=item,
                chunks=chunks,
            )
            for index, item in enumerate(evidence)
        ]

        if any(item.status == "needs_review" for item in bindings):
            status = "needs_review"
        elif any(item.status == "unbound" for item in bindings):
            status = "unbound"
        else:
            status = "bound"

        relevant: dict[tuple[int, int], BoundChunk] = {}

        for item in bindings:
            if item.status != "bound":
                continue

            for chunk in item.matched_chunks:
                relevant[(chunk.document_id, chunk.chunk_index)] = chunk

        return GoldenBindingResult(
            status=status,
            relevant_chunks=list(relevant.values()),
            evidence=bindings,
        )

    def _read_chunks(
        self,
        *,
        user_id: int,
        targets: list[DocumentTarget],
    ) -> list[BoundChunk]:
        chunks: list[BoundChunk] = []

        for target in targets:
            collection = resolve_qdrant_collection(
                profile=target.processing_profile,
                settings=self.settings,
            )

            scope = build_point_scope_filter(
                PointScope(
                    user_id,
                    target.document_id,
                    target.processing_run_id,
                )
            )
            scope.must.append(
                models.FieldCondition(
                    key="processing_profile",
                    match=models.MatchValue(
                        value=target.processing_profile.value,
                    ),
                )
            )

            offset = None

            while True:
                points, offset = self.client.scroll(
                    collection,
                    scroll_filter=scope,
                    limit=256,
                    offset=offset,
                    with_vectors=False,
                    with_payload=[
                        "chunk_index",
                        "text",
                        "source",
                        "page",
                        "section",
                    ],
                )

                for point in points:
                    payload = point.payload or {}

                    if (
                        not isinstance(payload.get("chunk_index"), int)
                        or not isinstance(payload.get("text"), str)
                        or not isinstance(payload.get("source"), str)
                    ):
                        continue

                    chunks.append(
                        BoundChunk(
                            point_id=str(point.id),
                            document_id=target.document_id,
                            processing_run_id=target.processing_run_id,
                            processing_profile=target.processing_profile,
                            chunk_index=payload["chunk_index"],
                            text=payload["text"],
                            source=payload["source"],
                            page=(
                                payload.get("page")
                                if isinstance(payload.get("page"), int)
                                else None
                            ),
                            section=(
                                payload.get("section")
                                if isinstance(payload.get("section"), str)
                                else None
                            ),
                        )
                    )

                if offset is None:
                    break

        return chunks

    def _bind_one(
        self,
        *,
        evidence_index: int,
        evidence: GoldenEvidence,
        chunks: list[BoundChunk],
    ) -> EvidenceBinding:
        candidates = [
            chunk
            for chunk in chunks
            if self._metadata_matches(
                evidence=evidence,
                chunk=chunk,
            )
        ]

        options: list[_BindingOption] = []

        for chunk in candidates:
            options.append(
                _BindingOption(
                    chunks=(chunk,),
                    score=self._text_score(
                        evidence.evidence_text,
                        chunk.text,
                    ),
                )
            )

        by_scope: dict[
            tuple[int, int],
            list[BoundChunk],
        ] = {}

        for chunk in candidates:
            by_scope.setdefault(
                (chunk.document_id, chunk.processing_run_id),
                [],
            ).append(chunk)

        for group in by_scope.values():
            ordered = sorted(
                group,
                key=lambda chunk: chunk.chunk_index,
            )

            for left, right in pairwise(ordered):
                if right.chunk_index != left.chunk_index + 1:
                    continue

                options.append(
                    _BindingOption(
                        chunks=(left, right),
                        score=self._text_score(
                            evidence.evidence_text,
                            left.text + "\n" + right.text,
                        ),
                    )
                )

        options = [
            option
            for option in options
            if option.score >= self.MIN_SCORE
        ]

        if not options:
            return EvidenceBinding(
                evidence_index=evidence_index,
                status="unbound",
                matched_chunks=[],
                competing_matches=0,
            )

        options.sort(
            key=lambda option: (
                -option.score,
                len(option.chunks),
            ),
        )

        best = options[0]
        competing = [
            option
            for option in options[1:]
            if best.score - option.score <= self.AMBIGUITY_DELTA
            and len(option.chunks) == len(best.chunks)
            and {
                chunk.point_id
                for chunk in option.chunks
            } != {
                chunk.point_id
                for chunk in best.chunks
            }
        ]

        if competing:
            return EvidenceBinding(
                evidence_index=evidence_index,
                status="needs_review",
                score=best.score,
                matched_chunks=list(best.chunks),
                competing_matches=len(competing),
            )

        return EvidenceBinding(
            evidence_index=evidence_index,
            status="bound",
            score=best.score,
            matched_chunks=list(best.chunks),
            competing_matches=0,
        )

    def _metadata_matches(
        self,
        *,
        evidence: GoldenEvidence,
        chunk: BoundChunk,
    ) -> bool:
        if evidence.page is not None and chunk.page != evidence.page:
            return False

        if evidence.source:
            requested = self._normalize_source(evidence.source)
            actual = self._normalize_source(chunk.source)

            if requested != actual:
                return False

        if evidence.section:
            requested_section = self._normalize(evidence.section)
            actual_section = self._normalize(chunk.section or "")

            if (
                requested_section not in actual_section
                and actual_section not in requested_section
            ):
                return False

        return True

    @classmethod
    def _text_score(
        cls,
        evidence_text: str,
        chunk_text: str,
    ) -> float:
        evidence = cls._normalize(evidence_text)
        chunk = cls._normalize(chunk_text)

        if not evidence or not chunk:
            return 0.0

        if evidence in chunk:
            return 1.0

        evidence_tokens = evidence.split()
        chunk_tokens = set(chunk.split())

        if not evidence_tokens:
            return 0.0

        matched = sum(
            1
            for token in evidence_tokens
            if token in chunk_tokens
        )

        return min(
            1.0,
            matched / len(evidence_tokens),
        )

    @classmethod
    def _normalize_source(cls, value: str) -> str:
        portable = value.replace("\\", "/")
        return cls._normalize(PurePath(portable).name)

    @staticmethod
    def _normalize(value: str) -> str:
        text = unicodedata.normalize("NFKC", value)
        text = _ARABIC_DIACRITICS.sub("", text)
        text = text.replace("ـ", "")
        text = (
            text.replace("أ", "ا")
            .replace("إ", "ا")
            .replace("آ", "ا")
            .replace("ى", "ي")
        )
        text = _NON_WORD.sub(" ", text)
        return _SPACE.sub(" ", text).strip().lower()
