<?php

namespace Tests\Feature\Documents;

use App\Enums\ProcessingProfile;
use App\Jobs\ProcessDocumentJob;
use App\Models\User;
use App\Services\Documents\DocumentProcessingDispatcher;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalAiQueueRoutingTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * يعزل اختبارات queue routing عن FastAPI الحقيقي
     * مع إبقاء capability validation ضمن المسار الفعلي.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/api/v1/capabilities' => Http::response([
                'available_profiles' => [
                    ProcessingProfile::Cloud->value,
                    ProcessingProfile::HybridLocal->value,
                ],
            ]),
        ]);
    }

    public function test_cloud_processing_is_dispatched_to_default_queue(): void
    {
        Queue::fake();
        Storage::fake('documents');

        config()->set('queue.processing.cloud_queue', 'default');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'cloud.txt',
                "Cloud processing document.\n",
            ),
        );

        $processingRun = app(DocumentProcessingDispatcher::class)
            ->dispatchInitial(
                $document,
                ProcessingProfile::Cloud,
            );

        Queue::assertPushed(
            ProcessDocumentJob::class,
            function (ProcessDocumentJob $job) use ($processingRun): bool {
                return $job->processingRunId === $processingRun->id
                    && $job->queue === 'default';
            },
        );

        $this->assertSame(
            ProcessingProfile::Cloud,
            $processingRun->fresh()->profile,
        );
    }

    public function test_hybrid_local_processing_is_dispatched_to_ai_local_queue(): void
    {
        Queue::fake();
        Storage::fake('documents');

        config()->set('queue.processing.local_queue', 'ai-local');

        $document = app(DocumentStorageService::class)->storePermanent(
            User::factory()->create(),
            UploadedFile::fake()->createWithContent(
                'local.txt',
                "Local processing document.\n",
            ),
        );

        $processingRun = app(DocumentProcessingDispatcher::class)
            ->dispatchInitial(
                $document,
                ProcessingProfile::HybridLocal,
            );

        Queue::assertPushed(
            ProcessDocumentJob::class,
            function (ProcessDocumentJob $job) use ($processingRun): bool {
                return $job->processingRunId === $processingRun->id
                    && $job->queue === 'ai-local';
            },
        );

        $this->assertSame(
            ProcessingProfile::HybridLocal,
            $processingRun->fresh()->profile,
        );
    }

    public function test_local_ai_queue_contract_is_serialized_to_one_worker(): void
    {
        $this->assertSame(
            'ai-local',
            config('queue.processing.local_queue'),
        );

        $this->assertSame(
            1,
            config('queue.processing.local_concurrency'),
        );

        $compose = file_get_contents(
            base_path('compose.yaml'),
        );

        $this->assertIsString($compose);

        $this->assertStringContainsString(
            'ai-local-worker:',
            $compose,
        );

        $this->assertStringContainsString(
            '--queue=${LOCAL_AI_QUEUE:-ai-local}',
            $compose,
        );

        $this->assertStringContainsString(
            'LOCAL_AI_QUEUE_CONCURRENCY: ${LOCAL_AI_QUEUE_CONCURRENCY:-1}',
            $compose,
        );
    }
}
