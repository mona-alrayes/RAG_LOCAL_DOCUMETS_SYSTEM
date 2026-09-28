<?php

namespace Tests\Unit\Services\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Models\User;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\ProcessingRunFailureFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessingRunFailureFinalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_processing_failure_marks_run_and_document_failed(): void
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

        app(ProcessingRunFailureFinalizer::class)->finalize(
            processingRunId: $processingRun->id,
            errorCode: 'ai_service_validation_failed',
            failureReason: 'The document processing request was rejected.',
        );

        $freshRun = $processingRun->fresh();
        $freshDocument = $document->fresh();

        $this->assertSame(
            ProcessingRunStatus::Failed,
            $freshRun->status,
        );

        $this->assertSame(
            'ai_service_validation_failed',
            $freshRun->error_code,
        );

        $this->assertSame(
            'The document processing request was rejected.',
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

    public function test_repeated_finalization_is_idempotent_and_preserves_first_failure_metadata(): void
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

        $finalizer = app(ProcessingRunFailureFinalizer::class);

        $finalizer->finalize(
            processingRunId: $processingRun->id,
            errorCode: 'first_error',
            failureReason: 'First safe failure reason.',
        );

        $firstFinalizedRun = $processingRun->fresh();

        $this->assertNotNull(
            $firstFinalizedRun->failed_at,
        );

        $firstFailedAt = $firstFinalizedRun->failed_at;

        $finalizer->finalize(
            processingRunId: $processingRun->id,
            errorCode: 'second_error',
            failureReason: 'Second failure reason must not overwrite the first.',
        );

        $secondFinalizedRun = $processingRun->fresh();

        $this->assertSame(
            ProcessingRunStatus::Failed,
            $secondFinalizedRun->status,
        );

        $this->assertSame(
            'first_error',
            $secondFinalizedRun->error_code,
        );

        $this->assertSame(
            'First safe failure reason.',
            $secondFinalizedRun->failure_reason,
        );

        $this->assertTrue(
            $secondFinalizedRun->failed_at->equalTo($firstFailedAt),
        );
    }

    public function test_late_failure_does_not_corrupt_indexed_run(): void
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
            'indexed_at' => now()->subSecond(),
        ]);

        $document->active_processing_run_id = $processingRun->id;
        $document->status = DocumentStatus::Ready;
        $document->save();

        /*
         * نأخذ القيمة المرجعية من قاعدة البيانات نفسها حتى لا تعتمد
         * المقارنة على اختلاف دقة microseconds بين PHP و MySQL.
         */
        $storedIndexedAt = $processingRun->fresh()->indexed_at;

        app(ProcessingRunFailureFinalizer::class)->finalize(
            processingRunId: $processingRun->id,
            errorCode: 'processing_retries_exhausted',
            failureReason: 'Document processing failed after all allowed retry attempts.',
        );

        $freshRun = $processingRun->fresh();
        $freshDocument = $document->fresh();

        $this->assertSame(
            ProcessingRunStatus::Indexed,
            $freshRun->status,
        );

        $this->assertNull(
            $freshRun->error_code,
        );

        $this->assertNull(
            $freshRun->failure_reason,
        );

        $this->assertNull(
            $freshRun->failed_at,
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

    public function test_reprocessing_failure_keeps_previous_active_run_and_document_ready(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'document.txt',
                "Document content.\n",
            ),
        );

        $activeRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'qdrant_collection' => 'rag_documents_cloud',
            'indexed_at' => now(),
        ]);

        $replacementRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Processing,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'started_at' => now(),
        ]);

        $document->active_processing_run_id = $activeRun->id;
        $document->status = DocumentStatus::Ready;
        $document->save();

        app(ProcessingRunFailureFinalizer::class)->finalize(
            processingRunId: $replacementRun->id,
            errorCode: 'document_parser_not_configured',
            failureReason: 'Document processing failed and cannot be retried.',
        );

        $freshDocument = $document->fresh();
        $freshReplacementRun = $replacementRun->fresh();
        $freshActiveRun = $activeRun->fresh();

        $this->assertSame(
            ProcessingRunStatus::Failed,
            $freshReplacementRun->status,
        );

        $this->assertSame(
            'document_parser_not_configured',
            $freshReplacementRun->error_code,
        );

        $this->assertNotNull(
            $freshReplacementRun->failed_at,
        );

        $this->assertSame(
            ProcessingRunStatus::Indexed,
            $freshActiveRun->status,
        );

        $this->assertSame(
            $activeRun->id,
            $freshDocument->active_processing_run_id,
        );

        $this->assertSame(
            DocumentStatus::Ready,
            $freshDocument->status,
        );
    }
}
