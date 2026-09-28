<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessDocumentJob;
use App\Models\User;
use App\Services\Admin\RetryProcessingRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class AdminRetryTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.ai_service.base_url' => 'http://ai.test', 'services.ai_service.internal_api_key' => 'test-key']);
        Http::fake(['*/api/v1/capabilities' => Http::response(['available_profiles' => ['cloud', 'hybrid_local']])]);
    }

    public function test_failed_initial_run_creates_new_attempt_and_dispatches_once(): void
    {
        $doc = $this->document('failed');
        $old = $doc->processingRuns()->create(['profile' => 'hybrid_local', 'status' => 'failed', 'kind' => 'initial', 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $new = app(RetryProcessingRun::class)->execute($this->admin(), $old->id);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('failed', $old->fresh()->status->value);
        $this->assertSame('queued', $doc->fresh()->status->value);
        Queue::assertPushed(ProcessDocumentJob::class, fn ($job) => $job->processingRunId === $new->id && $job->queue === 'ai-local');
        try {
            app(RetryProcessingRun::class)->execute($this->admin(), $old->id);
            $this->fail('Duplicate retry accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(2, $doc->processingRuns()->count());
    }

    public function test_infected_document_never_dispatches_processing(): void
    {
        $doc = $this->document('infected');
        $old = $doc->processingRuns()->create(['profile' => 'cloud', 'status' => 'failed', 'kind' => 'initial', 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        try {
            app(RetryProcessingRun::class)->execute($this->admin(), $old->id);
            $this->fail('Infected retry accepted');
        } catch (ValidationException) {
        }
        Queue::assertNothingPushed();
    }

    public function test_regular_user_is_denied_even_when_calling_service_directly(): void
    {
        $this->expectException(AuthorizationException::class);
        app(RetryProcessingRun::class)->execute(User::factory()->create(), 1);
    }
}
