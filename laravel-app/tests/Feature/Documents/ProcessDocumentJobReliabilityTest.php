<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Exceptions\AiServiceException;
use App\Jobs\ProcessDocumentJob;
use App\Models\User;
use App\Services\Ai\AiServiceClient;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\ProcessingRunActivator;
use App\Services\Documents\ProcessingRunFailureClassifier;
use App\Services\Documents\ProcessingRunFailureFinalizer;
use App\Services\Documents\ProcessingRunProgressor;
use App\Services\Documents\ProcessingRunResultPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

class ProcessDocumentJobReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exhausted_job_preserves_original_ai_failure(): void
    {
        Storage::fake('documents');
        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(), UploadedFile::fake()->createWithContent('a.txt', 'test')
        );
        $run = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal, 'status' => ProcessingRunStatus::Processing,
            'profile_snapshot' => [], 'stage_timings_ms' => [],
        ]);
        (new ProcessDocumentJob($run->id))->failed(new AiServiceException(
            message: 'private provider response', statusCode: 500, errorCode: 'local_resource_exhausted'
        ));
        $this->assertSame('local_resource_exhausted', $run->fresh()->error_code);
        $this->assertStringContainsString('memory', $run->fresh()->failure_reason);
        $this->assertStringNotContainsString('private', $run->fresh()->failure_reason);
        $this->actingAs($document->user)->get(route('documents.show', $document))
            ->assertOk()->assertSee(__('documents.failure.local_resource_exhausted'))
            ->assertDontSee('private provider response');
    }

    public function test_job_declares_bounded_retry_backoff_and_timeout_policy(): void
    {
        $reflection = new ReflectionClass(ProcessDocumentJob::class);

        $tries = $reflection->getAttributes(Tries::class);
        $backoff = $reflection->getAttributes(Backoff::class);
        $timeout = $reflection->getAttributes(Timeout::class);

        $this->assertCount(1, $tries);
        $this->assertCount(1, $backoff);
        $this->assertCount(1, $timeout);

        $this->assertSame(
            [3],
            $tries[0]->getArguments(),
        );

        $this->assertSame(
            [[15, 60]],
            $backoff[0]->getArguments(),
        );

        $this->assertSame(
            [330],
            $timeout[0]->getArguments(),
        );
    }

    public function test_retryable_failure_reuses_same_run_without_premature_finalization(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'document.txt',
                "Document content.\n",
            ),
        );

        $processingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Pending,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $this->mock(
            AiServiceClient::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('processDocument')
                    ->twice()
                    ->andThrow(
                        new AiServiceException(
                            message: 'Temporary AI service failure.',
                            statusCode: 503,
                        ),
                    );

                $mock->shouldNotReceive('deleteProcessingRunPoints');
            },
        );

        $job = new ProcessDocumentJob($processingRun->id);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $job->handle(
                    app(AiServiceClient::class),
                    app(ProcessingRunProgressor::class),
                    app(ProcessingRunResultPersister::class),
                    app(ProcessingRunActivator::class),
                    app(ProcessingRunFailureClassifier::class),
                    app(ProcessingRunFailureFinalizer::class),
                );

                $this->fail(
                    'Expected retryable AI service failure was not thrown.',
                );
            } catch (AiServiceException $exception) {
                $this->assertSame(
                    503,
                    $exception->statusCode,
                );
            }
        }

        $freshRun = $processingRun->fresh();
        $freshDocument = $document->fresh();

        $this->assertSame(
            $processingRun->id,
            $job->processingRunId,
        );

        $this->assertSame(
            1,
            $document->processingRuns()->count(),
        );

        $this->assertSame(
            ProcessingRunStatus::Processing,
            $freshRun->status,
        );

        $this->assertNotNull(
            $freshRun->started_at,
        );

        $this->assertNull(
            $freshRun->failed_at,
        );

        $this->assertNull(
            $freshRun->error_code,
        );

        $this->assertNull(
            $freshRun->failure_reason,
        );

        $this->assertSame(
            DocumentStatus::Processing,
            $freshDocument->status,
        );

        $this->assertNull(
            $freshDocument->active_processing_run_id,
        );
    }

    public function test_exhausted_retryable_failure_finalizes_same_run(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'document.txt',
                "Document content.\n",
            ),
        );

        $processingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Processing,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'started_at' => now(),
        ]);

        $job = new ProcessDocumentJob($processingRun->id);

        $job->failed(
            new AiServiceException(
                message: 'Temporary AI service failure.',
                statusCode: 503,
            ),
        );

        $freshRun = $processingRun->fresh();
        $freshDocument = $document->fresh();

        $this->assertSame(
            1,
            $document->processingRuns()->count(),
        );

        $this->assertSame(
            ProcessingRunStatus::Failed,
            $freshRun->status,
        );

        $this->assertSame(
            'processing_retries_exhausted',
            $freshRun->error_code,
        );

        $this->assertSame(
            'Document processing failed after all allowed retry attempts.',
            $freshRun->failure_reason,
        );

        $this->assertNotNull(
            $freshRun->failed_at,
        );

        $this->assertSame(
            DocumentStatus::Failed,
            $freshDocument->status,
        );

        $this->assertNull(
            $freshDocument->active_processing_run_id,
        );
    }

    public function test_timeout_failure_uses_timeout_specific_terminal_metadata(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'document.txt',
                "Document content.\n",
            ),
        );

        $processingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Processing,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'started_at' => now(),
        ]);

        $job = new ProcessDocumentJob($processingRun->id);

        $job->failed(
            new TimeoutExceededException(
                'ProcessDocumentJob has timed out.',
            ),
        );

        $freshRun = $processingRun->fresh();
        $freshDocument = $document->fresh();

        $this->assertSame(
            ProcessingRunStatus::Failed,
            $freshRun->status,
        );

        $this->assertSame(
            'processing_timeout_exhausted',
            $freshRun->error_code,
        );

        $this->assertSame(
            'Document processing timed out after all allowed attempts.',
            $freshRun->failure_reason,
        );

        $this->assertNotNull(
            $freshRun->failed_at,
        );

        $this->assertSame(
            DocumentStatus::Failed,
            $freshDocument->status,
        );
    }

    public function test_late_failed_hook_does_not_corrupt_already_indexed_run(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'document.txt',
                "Document content.\n",
            ),
        );

        $processingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'qdrant_collection' => 'rag_documents_cloud',
            'indexed_at' => now(),
        ]);

        $document->active_processing_run_id = $processingRun->id;
        $document->status = DocumentStatus::Ready;
        $document->save();

        $storedIndexedAt = $processingRun->fresh()->indexed_at;

        $job = new ProcessDocumentJob($processingRun->id);

        $job->failed(
            new AiServiceException(
                message: 'Late temporary failure.',
                statusCode: 503,
            ),
        );

        $freshRun = $processingRun->fresh();
        $freshDocument = $document->fresh();

        $this->assertSame(
            ProcessingRunStatus::Indexed,
            $freshRun->status,
        );

        $this->assertNull(
            $freshRun->failed_at,
        );

        $this->assertNull(
            $freshRun->error_code,
        );

        $this->assertNull(
            $freshRun->failure_reason,
        );

        $this->assertTrue(
            $freshRun->indexed_at->equalTo($storedIndexedAt),
        );

        $this->assertSame(
            $processingRun->id,
            $freshDocument->active_processing_run_id,
        );

        $this->assertSame(
            DocumentStatus::Ready,
            $freshDocument->status,
        );
    }
}
