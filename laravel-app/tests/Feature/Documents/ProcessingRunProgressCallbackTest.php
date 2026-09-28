<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Documents\DocumentStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessingRunProgressCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK_SECRET = 'h9-callback-only-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        config()->set(
            'services.processing_callback.secret',
            self::CALLBACK_SECRET,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_authenticated_callback_moves_initial_run_to_indexing(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Processing,
        );

        $response = $this->postJson(
            $this->callbackUrl($processingRun),
            $this->callbackPayload($document, $processingRun),
            $this->callbackHeaders(),
        );

        $response->assertOk()->assertExactJson([
            'event' => 'indexing_started',
            'processing_run_id' => $processingRun->id,
            'status' => 'indexing',
        ]);

        $freshRun = $processingRun->fresh();

        $this->assertSame(ProcessingRunStatus::Indexing, $freshRun->status);
        $this->assertNotNull($freshRun->indexing_started_at);
        $this->assertSame(DocumentStatus::Indexing, $document->fresh()->status);
    }

    public function test_callback_replay_keeps_the_original_indexing_timestamp(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Processing,
        );
        $firstEventAt = CarbonImmutable::parse('2026-09-03 06:10:00');
        Carbon::setTestNow($firstEventAt);

        $this->postJson(
            $this->callbackUrl($processingRun),
            $this->callbackPayload($document, $processingRun),
            $this->callbackHeaders(),
        )->assertOk();

        $documentUpdatedAt = $document->fresh()->updated_at;

        Carbon::setTestNow($firstEventAt->addMinute());

        $this->postJson(
            $this->callbackUrl($processingRun),
            $this->callbackPayload($document, $processingRun),
            $this->callbackHeaders(),
        )->assertOk();

        $this->assertTrue(
            $processingRun->fresh()->indexing_started_at->equalTo($firstEventAt),
        );
        $this->assertTrue(
            $document->fresh()->updated_at->equalTo($documentUpdatedAt),
        );
    }

    public function test_callback_requires_the_dedicated_secret(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Processing,
        );
        $payload = $this->callbackPayload($document, $processingRun);

        $this->postJson($this->callbackUrl($processingRun), $payload)
            ->assertUnauthorized();

        $this->postJson(
            $this->callbackUrl($processingRun),
            $payload,
            ['X-Processing-Callback-Secret' => 'forged-secret'],
        )->assertUnauthorized();

        $this->assertSame(
            ProcessingRunStatus::Processing,
            $processingRun->fresh()->status,
        );
    }

    public function test_callback_rejects_route_payload_id_mismatch(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Processing,
        );
        $payload = $this->callbackPayload($document, $processingRun);
        $payload['processing_run_id']++;

        $this->postJson(
            $this->callbackUrl($processingRun),
            $payload,
            $this->callbackHeaders(),
        )->assertUnprocessable();

        $this->assertSame(
            ProcessingRunStatus::Processing,
            $processingRun->fresh()->status,
        );
    }

    public function test_callback_rejects_a_run_owned_by_another_document(): void
    {
        $document = $this->createDocument();
        $otherDocument = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Processing,
        );

        $this->postJson(
            $this->callbackUrl($processingRun),
            $this->callbackPayload($otherDocument, $processingRun),
            $this->callbackHeaders(),
        )->assertNotFound();

        $this->assertSame(
            ProcessingRunStatus::Processing,
            $processingRun->fresh()->status,
        );
    }

    public function test_callback_rejects_a_forged_user_identifier(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Processing,
        );
        $payload = $this->callbackPayload($document, $processingRun);
        $payload['user_id']++;

        $this->postJson(
            $this->callbackUrl($processingRun),
            $payload,
            $this->callbackHeaders(),
        )->assertNotFound();

        $this->assertSame(
            ProcessingRunStatus::Processing,
            $processingRun->fresh()->status,
        );
    }

    public function test_callback_rejects_invalid_previous_state_and_event(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Pending,
        );

        $this->postJson(
            $this->callbackUrl($processingRun),
            $this->callbackPayload($document, $processingRun),
            $this->callbackHeaders(),
        )->assertConflict();

        $payload = $this->callbackPayload($document, $processingRun);
        $payload['event'] = 'indexed';

        $this->postJson(
            $this->callbackUrl($processingRun),
            $payload,
            $this->callbackHeaders(),
        )->assertUnprocessable();

        $this->assertSame(
            ProcessingRunStatus::Pending,
            $processingRun->fresh()->status,
        );
    }

    public function test_reprocessing_callback_keeps_document_ready_on_active_run(): void
    {
        $document = $this->createDocument();
        $activeRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Indexed,
        );
        $replacementRun = $this->createRun(
            $document,
            ProcessingRunKind::Reprocessing,
            ProcessingRunStatus::Processing,
        );

        $document->active_processing_run_id = $activeRun->id;
        $document->status = DocumentStatus::Ready;
        $document->save();

        $this->postJson(
            $this->callbackUrl($replacementRun),
            $this->callbackPayload($document, $replacementRun),
            $this->callbackHeaders(),
        )->assertOk();

        $this->assertSame(
            ProcessingRunStatus::Indexing,
            $replacementRun->fresh()->status,
        );
        $this->assertSame(DocumentStatus::Ready, $document->fresh()->status);
        $this->assertSame(
            $activeRun->id,
            $document->fresh()->active_processing_run_id,
        );
    }

    private function createDocument(): Document
    {
        return app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
            ),
        );
    }

    private function createRun(
        Document $document,
        ProcessingRunKind $kind,
        ProcessingRunStatus $status,
    ): ProcessingRun {
        return $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => $status,
            'kind' => $kind,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);
    }

    private function callbackUrl(ProcessingRun $processingRun): string
    {
        return "/internal/api/v1/processing-runs/{$processingRun->id}/events";
    }

    /**
     * @return array<string, int|string>
     */
    private function callbackPayload(
        Document $document,
        ProcessingRun $processingRun,
    ): array {
        return [
            'event' => 'indexing_started',
            'user_id' => $document->user_id,
            'document_id' => $document->id,
            'processing_run_id' => $processingRun->id,
            'correlation_id' => 'h9-test-correlation-id',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function callbackHeaders(): array
    {
        return [
            'X-Processing-Callback-Secret' => self::CALLBACK_SECRET,
        ];
    }
}
