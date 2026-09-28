<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Exceptions\AiServiceException;
use App\Jobs\ProcessDocumentJob;
use App\Models\User;
use App\Services\Ai\AiServiceClient;
use App\Services\Ai\Data\ProcessDocumentRequestData;
use App\Services\Ai\Data\ProcessDocumentResult;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\ProcessingRunActivator;
use App\Services\Documents\ProcessingRunFailureClassifier;
use App\Services\Documents\ProcessingRunFailureFinalizer;
use App\Services\Documents\ProcessingRunProgressor;
use App\Services\Documents\ProcessingRunResultPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Mockery\MockInterface;
use Tests\TestCase;

class ProcessDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_indexed_retry_cleans_previous_run_without_running_models_again(): void
    {
        Storage::fake('documents');
        $document = app(DocumentStorageService::class)->storePermanent(User::factory()->create(), UploadedFile::fake()->createWithContent('notes.txt', 'Notes'));
        $old = $document->processingRuns()->create(['profile' => ProcessingProfile::Cloud, 'status' => ProcessingRunStatus::Indexed, 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $active = $document->processingRuns()->create(['profile' => ProcessingProfile::HybridLocal, 'status' => ProcessingRunStatus::Indexed, 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $document->forceFill(['active_processing_run_id' => $active->id, 'status' => DocumentStatus::Ready])->save();
        $client = \Mockery::mock(AiServiceClient::class);
        $client->shouldNotReceive('processDocument');
        $client->shouldReceive('deleteProcessingRunPoints')->once()->ordered()->with($document->user_id, $document->id, $old->id, $old->profile)->andThrow(new AiServiceException(message: 'Cleanup unavailable', statusCode: 503));
        $client->shouldReceive('deleteProcessingRunPoints')->once()->ordered()->with($document->user_id, $document->id, $old->id, $old->profile);
        $this->app->instance(AiServiceClient::class, $client);
        $job = new ProcessDocumentJob($active->id);
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Expected retryable cleanup failure.');
        } catch (AiServiceException $exception) {
            $this->assertSame(503, $exception->statusCode);
        }
        $this->assertSame($active->id, $document->fresh()->active_processing_run_id);
        $this->app->call([$job, 'handle']);
        $this->assertSame($active->id, $document->fresh()->active_processing_run_id);
        $this->assertSame(ProcessingRunStatus::Indexed, $active->fresh()->status);
    }

    public function test_indexed_retry_cannot_reactivate_document_pending_deletion(): void
    {
        Storage::fake('documents');
        $document = app(DocumentStorageService::class)->storePermanent(User::factory()->create(), UploadedFile::fake()->createWithContent('notes.txt', 'Notes'));
        $run = $document->processingRuns()->create(['profile' => ProcessingProfile::Cloud, 'status' => ProcessingRunStatus::Indexed, 'profile_snapshot' => [], 'stage_timings_ms' => []]);
        $document->forceFill(['deletion_started_at' => now(), 'status' => DocumentStatus::Failed])->save();
        $client = \Mockery::mock(AiServiceClient::class);
        $client->shouldNotReceive('processDocument');
        $client->shouldNotReceive('deleteProcessingRunPoints');
        $this->app->instance(AiServiceClient::class, $client);
        $this->app->call([new ProcessDocumentJob($run->id), 'handle']);
        $this->assertNull($document->fresh()->active_processing_run_id);
        $this->assertSame(DocumentStatus::Failed, $document->fresh()->status);
    }

    public function test_job_persists_successful_processing_result(): void
    {
        Storage::fake('documents');

        $user = User::factory()->create();

        $document = app(DocumentStorageService::class)->storePermanent(
            $user,
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
            ),
        );

        $processingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Pending,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $otherProcessingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Pending,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $profileSnapshot = [
            'profile' => 'hybrid_local',
            'chunking' => [
                // يبقى إصدار التقطيع محفوظاً مع تشغيل المعالجة بعد الاستجابة من Python.
                'version' => 'markdown-sections-scoped-overlap-v3',
                'chunk_size' => 800,
                'chunk_overlap' => 80,
            ],
        ];

        $stageTimingsMs = [
            'parse' => 100,
            'chunk' => 20,
            'dense_embedding' => 300,
            'sparse_representation' => 40,
            'total' => 460,
        ];

        $warnings = [
            [
                'code' => 'sample_warning',
                'message' => 'Sample processing warning.',
                'stage' => 'parse',
            ],
        ];

        $result = new ProcessDocumentResult(
            documentId: $document->id,
            processingRunId: $processingRun->id,
            profile: ProcessingProfile::HybridLocal,
            status: ProcessingRunStatus::Indexed,
            qdrantCollection: 'rag_documents_hybrid_local',
            profileSnapshot: $profileSnapshot,
            totalPages: null,
            totalChunks: 12,
            vectorCount: 12,
            vectorDimension: 1024,
            stageTimingsMs: $stageTimingsMs,
            warnings: $warnings,
        );

        $this->mock(
            AiServiceClient::class,
            function (MockInterface $mock) use (
                $document,
                $processingRun,
                $result,
                $user,
            ): void {
                $mock->shouldReceive('processDocument')
                    ->once()
                    ->withArgs(function (
                        ProcessDocumentRequestData $data,
                        string $filePath,
                        string $fileName,
                    ) use ($document, $processingRun, $user): bool {
                        $this->assertSame(
                            $user->id,
                            $data->userId,
                        );

                        $this->assertSame(
                            $document->id,
                            $data->documentId,
                        );

                        $this->assertSame(
                            $processingRun->id,
                            $data->processingRunId,
                        );

                        $this->assertSame(
                            ProcessingProfile::HybridLocal,
                            $data->processingProfile,
                        );

                        $this->assertSame(
                            $document->file_type,
                            $data->fileType,
                        );

                        $this->assertSame(
                            $document->file_path,
                            $filePath,
                        );

                        $this->assertSame(
                            $document->original_name,
                            $fileName,
                        );

                        return true;
                    })
                    ->andReturnUsing(function () use (
                        $processingRun,
                        $result,
                    ): ProcessDocumentResult {
                        $processingRun->newQuery()
                            ->whereKey($processingRun->getKey())
                            ->update([
                                'status' => ProcessingRunStatus::Indexing,
                                'indexing_started_at' => now(),
                            ]);

                        return $result;
                    });
            },
        );

        (new ProcessDocumentJob($processingRun->id))->handle(
            app(AiServiceClient::class),
            app(ProcessingRunProgressor::class),
            app(ProcessingRunResultPersister::class),
            app(ProcessingRunActivator::class),
            app(ProcessingRunFailureClassifier::class),
            app(ProcessingRunFailureFinalizer::class),
        );

        $freshRun = $processingRun->fresh();

        $this->assertSame(
            ProcessingRunStatus::Indexed,
            $freshRun->status,
        );

        $this->assertSame(
            $profileSnapshot,
            $freshRun->profile_snapshot,
        );

        $this->assertNull(
            $freshRun->total_pages,
        );

        $this->assertSame(
            12,
            $freshRun->total_chunks,
        );

        $this->assertSame(
            12,
            $freshRun->vector_count,
        );

        $this->assertSame(
            1024,
            $freshRun->vector_dimension,
        );

        $this->assertSame(
            $stageTimingsMs,
            $freshRun->stage_timings_ms,
        );

        $this->assertSame(
            $warnings,
            $freshRun->warnings,
        );

        $this->assertSame(
            'rag_documents_hybrid_local',
            $freshRun->qdrant_collection,
        );

        $this->assertNotNull(
            $freshRun->started_at,
        );

        $this->assertNotNull(
            $freshRun->indexing_started_at,
        );

        $this->assertNotNull(
            $freshRun->indexed_at,
        );

        $this->assertSame(
            DocumentStatus::Ready,
            $document->fresh()->status,
        );

        $this->assertSame(
            $processingRun->id,
            $document->fresh()->active_processing_run_id,
        );

        $freshOtherRun = $otherProcessingRun->fresh();

        $this->assertSame(
            ProcessingRunStatus::Pending,
            $freshOtherRun->status,
        );

        $this->assertSame(
            [],
            $freshOtherRun->profile_snapshot,
        );

        $this->assertSame(
            0,
            $freshOtherRun->total_chunks,
        );

        $this->assertSame(
            0,
            $freshOtherRun->vector_count,
        );

        $this->assertNull(
            $freshOtherRun->qdrant_collection,
        );

        $this->assertNull(
            $freshOtherRun->indexed_at,
        );
    }

    public function test_job_rejects_indexed_result_when_callback_did_not_advance_run(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
            ),
        );

        $processingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Pending,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $result = new ProcessDocumentResult(
            documentId: $document->id,
            processingRunId: $processingRun->id,
            profile: ProcessingProfile::Cloud,
            status: ProcessingRunStatus::Indexed,
            qdrantCollection: 'rag_documents_cloud',
            profileSnapshot: [],
            totalPages: 1,
            totalChunks: 1,
            vectorCount: 1,
            vectorDimension: 1024,
            stageTimingsMs: [],
            warnings: [],
        );

        $this->mock(
            AiServiceClient::class,
            function (MockInterface $mock) use ($result): void {
                $mock->shouldReceive('processDocument')
                    ->once()
                    ->andReturn($result);
            },
        );

        $job = (new ProcessDocumentJob($processingRun->id))
            ->withFakeQueueInteractions();

        $job->handle(
            app(AiServiceClient::class),
            app(ProcessingRunProgressor::class),
            app(ProcessingRunResultPersister::class),
            app(ProcessingRunActivator::class),
            app(ProcessingRunFailureClassifier::class),
            app(ProcessingRunFailureFinalizer::class),
        );

        $job->assertFailedWith(LogicException::class);

        $freshRun = $processingRun->fresh();

        $this->assertSame(
            ProcessingRunStatus::Failed,
            $freshRun->status,
        );

        $this->assertSame(
            'processing_terminal_failure',
            $freshRun->error_code,
        );

        $this->assertSame(
            'Document processing failed and cannot be retried.',
            $freshRun->failure_reason,
        );

        $this->assertNotNull($freshRun->started_at);
        $this->assertNull($freshRun->indexing_started_at);
        $this->assertNull($freshRun->indexed_at);
        $this->assertNotNull($freshRun->failed_at);

        $freshDocument = $document->fresh();

        $this->assertSame(
            DocumentStatus::Failed,
            $freshDocument->status,
        );

        $this->assertNull(
            $freshDocument->active_processing_run_id,
        );
    }

    public function test_terminal_client_failure_finalizes_processing_run(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
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
                    ->once()
                    ->andThrow(
                        new AiServiceException(
                            'AI service failed.',
                        ),
                    );
            },
        );

        $job = (new ProcessDocumentJob($processingRun->id))
            ->withFakeQueueInteractions();

        $job->handle(
            app(AiServiceClient::class),
            app(ProcessingRunProgressor::class),
            app(ProcessingRunResultPersister::class),
            app(ProcessingRunActivator::class),
            app(ProcessingRunFailureClassifier::class),
            app(ProcessingRunFailureFinalizer::class),
        );

        $job->assertFailedWith(AiServiceException::class);

        $freshRun = $processingRun->fresh();

        $this->assertSame(
            ProcessingRunStatus::Failed,
            $freshRun->status,
        );

        $this->assertNotNull($freshRun->started_at);
        $this->assertNull($freshRun->indexing_started_at);
        $this->assertNotNull($freshRun->failed_at);

        $this->assertSame(
            'processing_terminal_failure',
            $freshRun->error_code,
        );

        $this->assertSame(
            'Document processing failed and cannot be retried.',
            $freshRun->failure_reason,
        );

        $this->assertSame(
            [],
            $freshRun->profile_snapshot,
        );

        $this->assertSame(
            0,
            $freshRun->total_chunks,
        );

        $this->assertSame(
            0,
            $freshRun->vector_count,
        );

        $this->assertNull(
            $freshRun->vector_dimension,
        );

        $this->assertSame(
            [],
            $freshRun->stage_timings_ms,
        );

        $this->assertNull(
            $freshRun->qdrant_collection,
        );

        $this->assertNull(
            $freshRun->indexed_at,
        );

        $freshDocument = $document->fresh();

        $this->assertSame(
            DocumentStatus::Failed,
            $freshDocument->status,
        );

        $this->assertNull(
            $freshDocument->active_processing_run_id,
        );
    }

    public function test_successful_reprocessing_switches_active_run_before_old_run_cleanup(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
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

        $newRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Pending,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $document->active_processing_run_id = $activeRun->id;
        $document->status = DocumentStatus::Ready;
        $document->save();

        $result = new ProcessDocumentResult(
            documentId: $document->id,
            processingRunId: $newRun->id,
            profile: ProcessingProfile::HybridLocal,
            status: ProcessingRunStatus::Indexed,
            qdrantCollection: 'rag_documents_hybrid_local',
            profileSnapshot: [],
            totalPages: null,
            totalChunks: 2,
            vectorCount: 2,
            vectorDimension: 1024,
            stageTimingsMs: [],
            warnings: [],
        );

        $this->mock(
            AiServiceClient::class,
            function (MockInterface $mock) use (
                $activeRun,
                $document,
                $newRun,
                $result,
            ): void {
                $mock->shouldReceive('processDocument')
                    ->once()
                    ->withArgs(function () use (
                        $activeRun,
                        $document,
                    ): bool {
                        $freshDocument = $document->fresh();

                        $this->assertSame(
                            $activeRun->id,
                            $freshDocument->active_processing_run_id,
                        );

                        $this->assertSame(
                            DocumentStatus::Ready,
                            $freshDocument->status,
                        );

                        return true;
                    })
                    ->andReturnUsing(function () use (
                        $newRun,
                        $result,
                    ): ProcessDocumentResult {
                        $newRun->newQuery()
                            ->whereKey($newRun->getKey())
                            ->update([
                                'status' => ProcessingRunStatus::Indexing,
                                'indexing_started_at' => now(),
                            ]);

                        return $result;
                    });

                $mock->shouldReceive('deleteProcessingRunPoints')
                    ->once()
                    ->withArgs(function (
                        int $userId,
                        int $documentId,
                        int $processingRunId,
                        ProcessingProfile $processingProfile,
                    ) use (
                        $activeRun,
                        $document,
                        $newRun,
                    ): bool {
                        $this->assertSame(
                            $newRun->id,
                            $document->fresh()->active_processing_run_id,
                        );

                        return $userId === (int) $document->user_id
                            && $documentId === (int) $document->id
                            && $processingRunId === (int) $activeRun->id
                            && $processingProfile === ProcessingProfile::Cloud;
                    });
            },
        );

        (new ProcessDocumentJob($newRun->id))->handle(
            app(AiServiceClient::class),
            app(ProcessingRunProgressor::class),
            app(ProcessingRunResultPersister::class),
            app(ProcessingRunActivator::class),
            app(ProcessingRunFailureClassifier::class),
            app(ProcessingRunFailureFinalizer::class),
        );

        $this->assertSame(
            ProcessingRunStatus::Indexed,
            $newRun->fresh()->status,
        );

        $this->assertSame(
            $newRun->id,
            $document->fresh()->active_processing_run_id,
        );

        $this->assertSame(
            DocumentStatus::Ready,
            $document->fresh()->status,
        );
    }

    public function test_terminal_reprocessing_failure_keeps_old_run_active_without_cleanup(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'trusted-notes.txt',
                "Trusted document content.\n",
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

        $newRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Pending,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $document->active_processing_run_id = $activeRun->id;
        $document->status = DocumentStatus::Ready;
        $document->save();

        $this->mock(
            AiServiceClient::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('processDocument')
                    ->once()
                    ->andThrow(
                        new AiServiceException(
                            'AI service failed.',
                        ),
                    );

                $mock->shouldNotReceive('deleteProcessingRunPoints');
            },
        );

        $job = (new ProcessDocumentJob($newRun->id))
            ->withFakeQueueInteractions();

        $job->handle(
            app(AiServiceClient::class),
            app(ProcessingRunProgressor::class),
            app(ProcessingRunResultPersister::class),
            app(ProcessingRunActivator::class),
            app(ProcessingRunFailureClassifier::class),
            app(ProcessingRunFailureFinalizer::class),
        );

        $job->assertFailedWith(AiServiceException::class);

        $freshDocument = $document->fresh();
        $freshNewRun = $newRun->fresh();

        $this->assertSame(
            $activeRun->id,
            $freshDocument->active_processing_run_id,
        );

        $this->assertSame(
            DocumentStatus::Ready,
            $freshDocument->status,
        );

        $this->assertSame(
            ProcessingRunStatus::Failed,
            $freshNewRun->status,
        );

        $this->assertSame(
            'processing_terminal_failure',
            $freshNewRun->error_code,
        );

        $this->assertSame(
            'Document processing failed and cannot be retried.',
            $freshNewRun->failure_reason,
        );

        $this->assertNotNull(
            $freshNewRun->failed_at,
        );
    }
}
