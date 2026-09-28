"""Server routing regression tests; no live retrieval or generation calls."""
import asyncio
import json

import pytest
from pydantic import ValidationError

from app.core.config import Settings
from app.processing.base import ProcessingProfile
from app.schemas.rag import RagQueryRequest
from app.services.cloud_retrieval import CloudRetrievalOutcome
from app.services.context import ContextService
from app.services.cross_profile_rank_fusion import CrossProfileRankFusionService
from app.services.prompt import PromptBuilder
from app.services.rag_query import RagQueryService
from app.services.retrieval_observability import RetrievalStageTimings


class EmptyRetrieval:
    def retrieve_observed(self, **kwargs):
        return CloudRetrievalOutcome((), RetrievalStageTimings(None, None, None))

    def retrieve_many_observed(self, *, targets, **kwargs):
        return [self.retrieve_observed() for target in targets]


class Provider:
    def __init__(self, profile, fail=False):
        self.profile = profile
        self.fail = fail

    async def stream(self, **kwargs):
        if self.fail:
            raise RuntimeError('selected provider unavailable')
        yield self.profile.value


def make_service(default, *, fail=False):
    selected = []

    def resolve(profile, settings):
        selected.append(profile)
        return Provider(profile, fail)

    return RagQueryService(
        settings=Settings(rag_generation_profile=default),
        cloud_retrieval=EmptyRetrieval(),
        local_retrieval_factory=EmptyRetrieval,
        context_service=ContextService(),
        prompt_builder=PromptBuilder(),
        fusion_service=CrossProfileRankFusionService(rrf_k=60),
        provider_resolver=resolve,
    ), selected


def request(profiles):
    return RagQueryRequest(user_id=7, question='What is supported?', document_targets=[
        {'document_id': i + 1, 'processing_run_id': i + 1, 'processing_profile': p}
        for i, p in enumerate(profiles)
    ])


@pytest.mark.parametrize(('profiles', 'default', 'expected'), [
    (['cloud'], 'hybrid_local', 'cloud'),
    (['cloud', 'cloud'], 'hybrid_local', 'cloud'),
    (['hybrid_local'], 'cloud', 'hybrid_local'),
    (['cloud', 'hybrid_local'], 'cloud', 'hybrid_local'),
    (['hybrid_local', 'cloud'], 'cloud', 'hybrid_local'),
])
def test_targets_route_generation_even_without_hits(profiles, default, expected):
    service, selected = make_service(default)
    prepared = service.prepare(request(profiles))
    assert prepared.provider.profile.value == expected
    assert [p.value for p in selected] == [expected]


@pytest.mark.parametrize('default', ['cloud', 'hybrid_local'])
def test_no_targets_preserves_default_and_insufficient_context(default):
    service, _ = make_service(default)
    prepared = service.prepare(request([]))
    assert prepared.provider.profile.value == default
    assert prepared.sources == ()
    assert json.loads(prepared.prompt.user_content)['retrieved_document_chunks'] == []
    assert 'إذا لم توجد أي مقاطع مسترجعة' in prepared.prompt.system_instructions


def test_mixed_provider_failure_has_no_cloud_fallback():
    service, selected = make_service('cloud', fail=True)
    prepared = service.prepare(request(['cloud', 'hybrid_local']))

    async def consume():
        return [event async for event in service.stream(prepared)]

    with pytest.raises(RuntimeError, match='selected provider unavailable'):
        asyncio.run(consume())
    assert selected == [ProcessingProfile.HYBRID_LOCAL]


@pytest.mark.parametrize('field', ['generation_profile', 'rag_generation_profile', 'provider'])
def test_request_cannot_override_generation(field):
    with pytest.raises(ValidationError):
        RagQueryRequest(user_id=7, question='test', **{field: 'cloud'})
