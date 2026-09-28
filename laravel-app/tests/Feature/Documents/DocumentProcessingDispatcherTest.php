<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Exceptions\AiServiceException;
use App\Exceptions\DocumentReprocessingException;
use App\Jobs\ProcessDocumentJob;
use App\Models\User;
use App\Services\Ai\AiServiceClient;
use App\Services\Documents\DocumentProcessingDispatcher;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DocumentProcessingDispatcherTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * يجعل كل processing profiles متاحة افتراضيًا داخل هذه الاختبارات
     * حتى تبقى اختبارات الـdispatcher معزولة عن FastAPI والشبكة.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $aiServiceClient = Mockery::mock(AiServiceClient::class);

        $aiServiceClient
            ->shouldReceive('capabilities')
            ->andReturn([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                    ProcessingProfile::HybridLocal->value,
                ],
            ]);

        $this->app->instance(
            AiServiceClient::class,
            $aiServiceClient,
        );
    }

    public function test_deletion_marker_blocks_initial_reprocessing_and_failed_retry_dispatch(): void
    {
        Queue::fake();
        Storage::fake('documents');
        $document = app(DocumentStorageService::class)->storePermanent(User::factory()->create(), UploadedFile::fake()->createWithContent('notes.txt', 'Notes'));
        $document->forceFill(['deletion_started_at' => now(), 'status' => DocumentStatus::Failed])->save();
        $dispatcher = app(DocumentProcessingDispatcher::class);
        try {
            $dispatcher->dispatchInitial($document, ProcessingProfile::Cloud);
            $this->fail('Initial processing must be blocked.');
        } catch (LogicException $exception) {
            $this->assertSame('Document deletion is in progress.', $exception->getMessage());
        }
        $this->assertSame(0, $document->processingRuns()->count());
        $indexed = $document->processingRuns()->create(['profile' => ProcessingProfile::Cloud, 'status' => ProcessingRunStatus::Indexed, 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $document->forceFill(['active_processing_run_id' => $indexed->id])->save();
        try {
            $dispatcher->dispatchReprocessing($document, ProcessingProfile::Cloud);
            $this->fail('Reprocessing must be blocked.');
        } catch (DocumentReprocessingException $exception) {
            $this->assertSame(DocumentReprocessingException::NO_ACTIVE_RUN, $exception->reason);
        }
        $failed = $document->processingRuns()->create(['profile' => ProcessingProfile::Cloud, 'status' => ProcessingRunStatus::Failed, 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        try {
            $dispatcher->dispatchFailedRetry($document, $failed);
            $this->fail('Failed retry must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('retry', $exception->errors());
        }
        $this->assertSame(2, $document->processingRuns()->count());
        Queue::assertNothingPushed();
    }

    public function test_it_creates_one_run_and_dispatches_processing_after_permanent_storage(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'notes.txt',
                "Permanent document content.\n",
            ),
        );

        $processingRun = app(DocumentProcessingDispatcher::class)
            ->dispatchInitial(
                $document,
                ProcessingProfile::HybridLocal,
            );

        $this->assertSame(
            DocumentStatus::Queued,
            $document->fresh()->status,
        );

        $this->assertSame(
            ProcessingProfile::HybridLocal,
            $processingRun->profile,
        );

        $this->assertSame(
            ProcessingRunStatus::Pending,
            $processingRun->status,
        );

        $this->assertSame(
            ProcessingRunKind::Initial,
            $processingRun->kind,
        );

        $this->assertSame([], $processingRun->profile_snapshot);
        $this->assertSame([], $processingRun->stage_timings_ms);
        $this->assertNotNull($processingRun->created_at);
        $this->assertNull($processingRun->started_at);
        $this->assertNull($processingRun->indexing_started_at);
        $this->assertNull($processingRun->failed_at);

        $this->assertDatabaseCount(
            'document_processing_runs',
            1,
        );

        Queue::assertPushed(
            ProcessDocumentJob::class,
            fn (ProcessDocumentJob $job): bool => $job->processingRunId
                === $processingRun->id,
        );
    }

    public function test_it_does_not_create_a_second_initial_run(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'notes.txt',
                "Permanent document content.\n",
            ),
        );

        $dispatcher = app(DocumentProcessingDispatcher::class);

        $dispatcher->dispatchInitial(
            $document,
            ProcessingProfile::Cloud,
        );

        try {
            $dispatcher->dispatchInitial(
                $document,
                ProcessingProfile::HybridLocal,
            );

            $this->fail(
                'Expected duplicate initial dispatch to be rejected.',
            );
        } catch (LogicException) {
            // Expected.
        }

        $this->assertDatabaseCount(
            'document_processing_runs',
            1,
        );

        Queue::assertPushed(
            ProcessDocumentJob::class,
            1,
        );
    }

    public function test_it_rejects_processing_while_file_is_still_quarantined(): void
    {
        Queue::fake();
        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $document = app(DocumentStorageService::class)->storeQuarantined(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'notes.txt',
                "Quarantined document content.\n",
            ),
        );

        try {
            app(DocumentProcessingDispatcher::class)
                ->dispatchInitial(
                    $document,
                    ProcessingProfile::Cloud,
                );

            $this->fail(
                'Expected quarantined document to be rejected.',
            );
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertDatabaseCount(
            'document_processing_runs',
            0,
        );

        Queue::assertNotPushed(ProcessDocumentJob::class);
    }

    public function test_it_dispatches_reprocessing_without_replacing_the_active_run(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'notes.txt',
                "Permanent document content.\n",
            ),
        );

        $activeRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $document->active_processing_run_id = $activeRun->id;
        $document->status = DocumentStatus::Ready;
        $document->save();

        $newRun = app(DocumentProcessingDispatcher::class)
            ->dispatchReprocessing(
                $document,
                ProcessingProfile::HybridLocal,
            );

        $freshDocument = $document->fresh();

        $this->assertSame(
            $activeRun->id,
            $freshDocument->active_processing_run_id,
        );

        $this->assertSame(
            DocumentStatus::Ready,
            $freshDocument->status,
        );

        $this->assertSame(
            ProcessingRunStatus::Pending,
            $newRun->status,
        );

        $this->assertSame(
            ProcessingProfile::HybridLocal,
            $newRun->profile,
        );

        $this->assertSame(
            ProcessingRunKind::Reprocessing,
            $newRun->kind,
        );

        $this->assertNotNull($newRun->created_at);
        $this->assertNull($newRun->started_at);

        $this->assertDatabaseCount(
            'document_processing_runs',
            2,
        );

        Queue::assertPushed(
            ProcessDocumentJob::class,
            fn (ProcessDocumentJob $job): bool => $job->processingRunId
                === $newRun->id,
        );
    }

    public function test_it_rejects_reprocessing_without_an_active_run(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'notes.txt',
                "Permanent document content.\n",
            ),
        );

        try {
            app(DocumentProcessingDispatcher::class)
                ->dispatchReprocessing(
                    $document,
                    ProcessingProfile::Cloud,
                );

            $this->fail(
                'Expected reprocessing without an active run to fail.',
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Document must have an active processing run before reprocessing.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount(
            'document_processing_runs',
            0,
        );

        Queue::assertNotPushed(ProcessDocumentJob::class);
    }

    public function test_it_rejects_concurrent_reprocessing_dispatches(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'notes.txt',
                "Permanent document content.\n",
            ),
        );

        $activeRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $document->active_processing_run_id = $activeRun->id;
        $document->save();

        $dispatcher = app(DocumentProcessingDispatcher::class);

        $dispatcher->dispatchReprocessing(
            $document,
            ProcessingProfile::HybridLocal,
        );

        try {
            $dispatcher->dispatchReprocessing(
                $document,
                ProcessingProfile::Cloud,
            );

            $this->fail(
                'Expected concurrent reprocessing to be rejected.',
            );
        } catch (LogicException $exception) {
            $this->assertSame(
                'Document reprocessing is already in progress.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount(
            'document_processing_runs',
            2,
        );

        Queue::assertPushed(
            ProcessDocumentJob::class,
            1,
        );
    }

    public function test_unavailable_reprocessing_profile_preserves_ready_document(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'notes.txt',
                "Permanent document content.\n",
            ),
        );

        $activeRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $document->forceFill([
            'active_processing_run_id' => $activeRun->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        $aiServiceClient = Mockery::mock(AiServiceClient::class);

        $aiServiceClient
            ->shouldReceive('capabilities')
            ->once()
            ->andReturn([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                ],
            ]);

        $this->app->instance(
            AiServiceClient::class,
            $aiServiceClient,
        );

        try {
            app(DocumentProcessingDispatcher::class)
                ->dispatchReprocessing(
                    $document,
                    ProcessingProfile::HybridLocal,
                );

            $this->fail(
                'Expected unavailable processing profile to be rejected.',
            );
        } catch (AiServiceException $exception) {
            $this->assertSame(
                'processing_profile_unavailable',
                $exception->errorCode,
            );
        }

        $freshDocument = $document->fresh();

        $this->assertSame(
            DocumentStatus::Ready,
            $freshDocument->status,
        );

        $this->assertSame(
            $activeRun->id,
            $freshDocument->active_processing_run_id,
        );

        $this->assertDatabaseCount(
            'document_processing_runs',
            1,
        );

        Queue::assertNotPushed(ProcessDocumentJob::class);
    }
}
