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
use App\Services\Documents\ProcessingRunProgressor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessingRunProgressorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_job_start_progress_is_idempotent_and_timestamped_once(): void
    {
        $document = $this->createDocument();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Pending,
        );
        $startedAt = CarbonImmutable::parse('2026-09-03 06:00:00');
        Carbon::setTestNow($startedAt);

        $progressor = app(ProcessingRunProgressor::class);
        $progressor->markProcessingStarted($processingRun->id);

        Carbon::setTestNow($startedAt->addMinute());
        $progressor->markProcessingStarted($processingRun->id);

        $freshRun = $processingRun->fresh();

        $this->assertSame(ProcessingRunStatus::Processing, $freshRun->status);
        $this->assertTrue($freshRun->started_at->equalTo($startedAt));
        $this->assertSame(DocumentStatus::Processing, $document->fresh()->status);
    }

    public function test_retry_from_indexing_does_not_regress_run_or_document(): void
    {
        $document = $this->createDocument();
        $startedAt = CarbonImmutable::parse('2026-09-03 06:00:00');
        $indexingStartedAt = $startedAt->addMinute();
        $processingRun = $this->createRun(
            $document,
            ProcessingRunKind::Initial,
            ProcessingRunStatus::Indexing,
            startedAt: $startedAt,
            indexingStartedAt: $indexingStartedAt,
        );

        app(ProcessingRunProgressor::class)
            ->markProcessingStarted($processingRun->id);

        $freshRun = $processingRun->fresh();

        $this->assertSame(ProcessingRunStatus::Indexing, $freshRun->status);
        $this->assertTrue($freshRun->started_at->equalTo($startedAt));
        $this->assertTrue(
            $freshRun->indexing_started_at->equalTo($indexingStartedAt),
        );
        $this->assertSame(DocumentStatus::Indexing, $document->fresh()->status);
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
        ?CarbonImmutable $startedAt = null,
        ?CarbonImmutable $indexingStartedAt = null,
    ): ProcessingRun {
        return $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => $status,
            'kind' => $kind,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'started_at' => $startedAt,
            'indexing_started_at' => $indexingStartedAt,
        ]);
    }
}
