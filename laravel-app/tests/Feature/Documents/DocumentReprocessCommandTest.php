<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\AiServiceClient;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DocumentReprocessCommandTest extends TestCase
{
    use DatabaseMigrations;

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

    public function test_owner_can_start_reprocessing(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $user = User::factory()->create();
        $document = $this->readyDocument($user);

        $activeRunId = $document->active_processing_run_id;

        $response = $this->actingAs($user)
            ->post(
                route('documents.reprocess', $document),
                [
                    'processing_profile' => ProcessingProfile::HybridLocal->value,
                ],
            );

        $response
            ->assertRedirectToRoute('documents.show', $document)
            ->assertSessionHas(
                'success',
                __('documents.commands.reprocess.started'),
            );

        $freshDocument = $document->fresh();

        $this->assertSame(
            $activeRunId,
            $freshDocument->active_processing_run_id,
        );

        $this->assertSame(
            DocumentStatus::Ready,
            $freshDocument->status,
        );

        $newRun = $freshDocument
            ->processingRuns()
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            ProcessingProfile::HybridLocal,
            $newRun->profile,
        );

        $this->assertSame(
            ProcessingRunStatus::Pending,
            $newRun->status,
        );

        $this->assertSame(
            ProcessingRunKind::Reprocessing,
            $newRun->kind,
        );

        $this->assertDatabaseCount(
            'document_processing_runs',
            2,
        );

        Queue::assertPushed(
            ProcessDocumentJob::class,
            fn (ProcessDocumentJob $job): bool => $job->processingRunId === $newRun->id,
        );
    }

    public function test_non_owner_cannot_reprocess_document(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $document = $this->readyDocument($owner);

        $this->actingAs($otherUser)
            ->post(
                route('documents.reprocess', $document),
                [
                    'processing_profile' => ProcessingProfile::Cloud->value,
                ],
            )
            ->assertForbidden();

        $this->assertDatabaseCount(
            'document_processing_runs',
            1,
        );

        Queue::assertNothingPushed();
    }

    public function test_reprocessing_is_rejected_when_processing_is_already_in_progress(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $user = User::factory()->create();
        $document = $this->readyDocument($user);

        $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Pending,
            'kind' => ProcessingRunKind::Reprocessing,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $response = $this->actingAs($user)
            ->post(
                route('documents.reprocess', $document),
                [
                    'processing_profile' => ProcessingProfile::Cloud->value,
                ],
            );

        $response
            ->assertRedirectToRoute('documents.show', $document)
            ->assertSessionHas(
                'error',
                __('documents.commands.reprocess.already_in_progress'),
            );

        $this->assertDatabaseCount(
            'document_processing_runs',
            2,
        );

        Queue::assertNothingPushed();
    }

    public function test_unavailable_profile_fails_closed_without_changing_ready_document(): void
    {
        Queue::fake();
        Storage::fake('documents');

        $user = User::factory()->create();
        $document = $this->readyDocument($user);

        $activeRunId = $document->active_processing_run_id;

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

        $response = $this->actingAs($user)
            ->post(
                route('documents.reprocess', $document),
                [
                    'processing_profile' => ProcessingProfile::HybridLocal->value,
                ],
            );

        $response
            ->assertRedirectToRoute('documents.show', $document)
            ->assertSessionHas(
                'error',
                __('documents.commands.reprocess.profile_unavailable'),
            );

        $freshDocument = $document->fresh();

        $this->assertSame(
            DocumentStatus::Ready,
            $freshDocument->status,
        );

        $this->assertSame(
            $activeRunId,
            $freshDocument->active_processing_run_id,
        );

        $this->assertDatabaseCount(
            'document_processing_runs',
            1,
        );

        Queue::assertNothingPushed();
    }

    private function readyDocument(User $user): Document
    {
        $document = app(DocumentStorageService::class)
            ->storePermanent(
                $user,
                UploadedFile::fake()->createWithContent(
                    'ready-document.txt',
                    "Ready document content.\n",
                ),
            );

        $activeRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $document->forceFill([
            'active_processing_run_id' => $activeRun->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        return $document->fresh();
    }
}
