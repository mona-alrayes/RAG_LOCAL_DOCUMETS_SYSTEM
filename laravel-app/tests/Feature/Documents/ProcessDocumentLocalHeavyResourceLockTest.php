<?php

namespace Tests\Feature\Documents;

use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunStatus;
use App\Exceptions\AiServiceException;
use App\Exceptions\LocalHeavyResourceBusyException;
use App\Jobs\ProcessDocumentJob;
use App\Models\User;
use App\Services\Ai\AiServiceClient;
use App\Services\Documents\DocumentStorageService;
use App\Services\Documents\ProcessingRunActivator;
use App\Services\Documents\ProcessingRunFailureClassifier;
use App\Services\Documents\ProcessingRunFailureFinalizer;
use App\Services\Documents\ProcessingRunProgressor;
use App\Services\Documents\ProcessingRunResultPersister;
use App\Services\Infrastructure\LocalHeavyResourceLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ProcessDocumentLocalHeavyResourceLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_hybrid_local_does_not_call_ai_service_when_global_lock_is_busy(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'local.txt',
                "Local document content.\n",
            ),
        );

        $processingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Pending,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $this->mock(
            AiServiceClient::class,
            function (MockInterface $mock): void {
                $mock->shouldNotReceive('processDocument');
                $mock->shouldNotReceive('deleteProcessingRunPoints');
            },
        );

        $lock = Mockery::mock(LocalHeavyResourceLock::class);

        $lock->shouldReceive('enabled')
            ->once()
            ->andReturnTrue();

        $lock->shouldReceive('acquireWithin')
            ->once()
            ->andReturnNull();

        $lock->shouldNotReceive('release');

        $job = new ProcessDocumentJob(
            $processingRun->id,
        );

        try {
            $job->handle(
                app(AiServiceClient::class),
                app(ProcessingRunProgressor::class),
                app(ProcessingRunResultPersister::class),
                app(ProcessingRunActivator::class),
                app(ProcessingRunFailureClassifier::class),
                app(ProcessingRunFailureFinalizer::class),
                $lock,
            );

            $this->fail(
                'Expected local heavy-resource lock contention.',
            );
        } catch (LocalHeavyResourceBusyException) {
            // Expected retryable contention.
        }

        $freshRun = $processingRun->fresh();

        $this->assertSame(
            $processingRun->id,
            $job->processingRunId,
        );

        $this->assertSame(
            1,
            $document->processingRuns()->count(),
        );

        $this->assertSame(
            ProcessingProfile::HybridLocal,
            $freshRun->profile,
        );

        $this->assertSame(
            ProcessingRunStatus::Processing,
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
    }

    public function test_local_lock_contention_is_classified_as_retryable(): void
    {
        $exception = new LocalHeavyResourceBusyException(
            'Local heavy resource is busy.',
        );

        $this->assertTrue(
            app(ProcessingRunFailureClassifier::class)
                ->isRetryable($exception),
        );
    }

    public function test_hybrid_local_releases_global_lock_when_ai_service_throws(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'local.txt',
                "Local document content.\n",
            ),
        );

        $processingRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
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
                            message: 'Temporary AI service failure.',
                            statusCode: 503,
                        ),
                    );

                $mock->shouldNotReceive(
                    'deleteProcessingRunPoints',
                );
            },
        );

        $lock = Mockery::mock(LocalHeavyResourceLock::class);

        $lock->shouldReceive('enabled')
            ->once()
            ->andReturnTrue();

        $lock->shouldReceive('acquireWithin')
            ->once()
            ->andReturn('local-lock-token');

        $lock->shouldReceive('release')
            ->once()
            ->with('local-lock-token')
            ->andReturnTrue();

        $job = new ProcessDocumentJob(
            $processingRun->id,
        );

        try {
            $job->handle(
                app(AiServiceClient::class),
                app(ProcessingRunProgressor::class),
                app(ProcessingRunResultPersister::class),
                app(ProcessingRunActivator::class),
                app(ProcessingRunFailureClassifier::class),
                app(ProcessingRunFailureFinalizer::class),
                $lock,
            );

            $this->fail(
                'Expected retryable AI service failure.',
            );
        } catch (AiServiceException $exception) {
            $this->assertSame(
                503,
                $exception->statusCode,
            );
        }

        $freshRun = $processingRun->fresh();

        $this->assertSame(
            1,
            $document->processingRuns()->count(),
        );

        $this->assertSame(
            ProcessingProfile::HybridLocal,
            $freshRun->profile,
        );

        $this->assertSame(
            ProcessingRunStatus::Processing,
            $freshRun->status,
        );

        $this->assertNull(
            $freshRun->failed_at,
        );

        $this->assertNull(
            $freshRun->error_code,
        );
    }

    public function test_cloud_processing_does_not_use_local_heavy_resource_lock(): void
    {
        Storage::fake('documents');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'cloud.txt',
                "Cloud document content.\n",
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
                            message: 'Temporary cloud failure.',
                            statusCode: 503,
                        ),
                    );

                $mock->shouldNotReceive(
                    'deleteProcessingRunPoints',
                );
            },
        );

        $lock = Mockery::mock(LocalHeavyResourceLock::class);

        $lock->shouldNotReceive('enabled');
        $lock->shouldNotReceive('acquireWithin');
        $lock->shouldNotReceive('release');

        $job = new ProcessDocumentJob(
            $processingRun->id,
        );

        try {
            $job->handle(
                app(AiServiceClient::class),
                app(ProcessingRunProgressor::class),
                app(ProcessingRunResultPersister::class),
                app(ProcessingRunActivator::class),
                app(ProcessingRunFailureClassifier::class),
                app(ProcessingRunFailureFinalizer::class),
                $lock,
            );

            $this->fail(
                'Expected retryable cloud failure.',
            );
        } catch (AiServiceException $exception) {
            $this->assertSame(
                503,
                $exception->statusCode,
            );
        }
    }
}
