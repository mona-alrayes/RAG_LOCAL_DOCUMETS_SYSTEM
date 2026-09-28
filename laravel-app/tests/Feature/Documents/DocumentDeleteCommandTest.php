<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Exceptions\AiServiceException;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Ai\AiServiceClient;
use App\Services\Conversations\ConversationRuntimeDocumentService;
use App\Services\Documents\DocumentStorageService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DocumentDeleteCommandTest extends TestCase
{
    use DatabaseMigrations;

    public function test_owner_can_delete_document_and_all_external_data(): void
    {
        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();

        [$document, $oldRun, $activeRun] = $this->readyDocument($user);

        $aiServiceClient = Mockery::mock(AiServiceClient::class);

        $aiServiceClient
            ->shouldReceive('deleteProcessingRunPoints')
            ->once()
            ->ordered()
            ->with(
                (int) $user->id,
                (int) $document->id,
                (int) $oldRun->id,
                ProcessingProfile::Cloud,
            );

        $aiServiceClient
            ->shouldReceive('deleteProcessingRunPoints')
            ->once()
            ->ordered()
            ->with(
                (int) $user->id,
                (int) $document->id,
                (int) $activeRun->id,
                ProcessingProfile::HybridLocal,
            );

        $this->app->instance(
            AiServiceClient::class,
            $aiServiceClient,
        );

        $this->actingAs($user)
            ->delete(route('documents.destroy', $document))
            ->assertRedirectToRoute('documents.index')
            ->assertSessionHas(
                'success',
                __('documents.commands.delete.success'),
            );

        Storage::disk('documents')
            ->assertMissing($document->file_path);

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);

        $this->assertDatabaseMissing('document_processing_runs', [
            'document_id' => $document->id,
        ]);
    }

    public function test_non_owner_cannot_delete_document(): void
    {
        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        [$document] = $this->readyDocument($owner);

        $aiServiceClient = Mockery::mock(AiServiceClient::class);

        $aiServiceClient
            ->shouldNotReceive('deleteProcessingRunPoints');

        $this->app->instance(
            AiServiceClient::class,
            $aiServiceClient,
        );

        $this->actingAs($otherUser)
            ->delete(route('documents.destroy', $document))
            ->assertForbidden();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
        ]);

        Storage::disk('documents')
            ->assertExists($document->file_path);
    }

    public function test_document_cannot_be_deleted_while_processing_is_in_progress(): void
    {
        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();

        [$document] = $this->readyDocument($user);

        $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Pending,
            'kind' => ProcessingRunKind::Reprocessing,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $aiServiceClient = Mockery::mock(AiServiceClient::class);

        $aiServiceClient
            ->shouldNotReceive('deleteProcessingRunPoints');

        $this->app->instance(
            AiServiceClient::class,
            $aiServiceClient,
        );

        $this->actingAs($user)
            ->delete(route('documents.destroy', $document))
            ->assertRedirectToRoute('documents.show', $document)
            ->assertSessionHas(
                'error',
                __('documents.commands.delete.processing_in_progress'),
            );

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
        ]);

        Storage::disk('documents')
            ->assertExists($document->file_path);

        $this->assertDatabaseCount(
            'document_processing_runs',
            3,
        );
    }

    public function test_failed_qdrant_cleanup_preserves_local_document_data(): void
    {
        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();

        [$document, $oldRun] = $this->readyDocument($user);

        $aiServiceClient = Mockery::mock(AiServiceClient::class);

        $aiServiceClient
            ->shouldReceive('deleteProcessingRunPoints')
            ->once()
            ->with(
                (int) $user->id,
                (int) $document->id,
                (int) $oldRun->id,
                ProcessingProfile::Cloud,
            )
            ->andThrow(
                new AiServiceException(
                    message: 'Qdrant cleanup failed.',
                ),
            );

        $this->app->instance(
            AiServiceClient::class,
            $aiServiceClient,
        );

        $this->actingAs($user)
            ->delete(route('documents.destroy', $document))
            ->assertRedirectToRoute('documents.show', $document)
            ->assertSessionHas(
                'error',
                __('documents.commands.delete.cleanup_failed'),
            );

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
        ]);

        $this->assertDatabaseCount(
            'document_processing_runs',
            2,
        );

        Storage::disk('documents')
            ->assertExists($document->file_path);
    }

    public function test_pending_and_scanning_documents_cannot_be_deleted(): void
    {
        Storage::fake('documents');
        Storage::fake('document_quarantine');
        $user = User::factory()->create();
        $client = Mockery::mock(AiServiceClient::class);
        $client->shouldNotReceive('deleteProcessingRunPoints');
        $this->app->instance(AiServiceClient::class, $client);
        foreach ([DocumentStatus::Pending, DocumentStatus::Scanning] as $status) {
            $document = app(DocumentStorageService::class)->storeQuarantined($user, UploadedFile::fake()->createWithContent('scan.txt', $status->value));
            $document->forceFill(['status' => $status])->save();
            $this->actingAs($user)->delete(route('documents.destroy', $document))->assertSessionHas('error');
            $this->assertSame($status, $document->fresh()->status);
            $this->assertNull($document->fresh()->deletion_started_at);
            Storage::disk('document_quarantine')->assertExists($document->file_path);
        }
    }

    public function test_quarantined_document_can_be_deleted_without_processing_runs(): void
    {
        Storage::fake('documents');
        Storage::fake('document_quarantine');

        $user = User::factory()->create();

        $document = app(DocumentStorageService::class)
            ->storeQuarantined(
                $user,
                UploadedFile::fake()->createWithContent(
                    'unsafe.txt',
                    "Quarantined document content.\n",
                ),
            );
        $document->forceFill(['status' => DocumentStatus::Infected])->save();

        $aiServiceClient = Mockery::mock(AiServiceClient::class);

        $aiServiceClient
            ->shouldNotReceive('deleteProcessingRunPoints');

        $this->app->instance(
            AiServiceClient::class,
            $aiServiceClient,
        );

        $this->actingAs($user)
            ->delete(route('documents.destroy', $document))
            ->assertRedirectToRoute('documents.index')
            ->assertSessionHas(
                'success',
                __('documents.commands.delete.success'),
            );

        Storage::disk('document_quarantine')
            ->assertMissing($document->file_path);

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);
    }

    public function test_partial_vector_cleanup_stays_unavailable_and_can_be_resumed(): void
    {
        Storage::fake('documents');
        Storage::fake('document_quarantine');
        $user = User::factory()->create();
        [$document, $oldRun, $activeRun] = $this->readyDocument($user);
        $client = Mockery::mock(AiServiceClient::class);
        $client->shouldReceive('deleteProcessingRunPoints')->once()->ordered()->with($user->id, $document->id, $oldRun->id, $oldRun->profile);
        $client->shouldReceive('deleteProcessingRunPoints')->once()->ordered()->with($user->id, $document->id, $activeRun->id, $activeRun->profile)->andThrow(new AiServiceException(message: 'Cleanup interrupted.'));
        $this->app->instance(AiServiceClient::class, $client);
        $this->actingAs($user)->delete(route('documents.destroy', $document))->assertSessionHas('error');
        $document->refresh();
        $this->assertNotNull($document->deletion_started_at);
        $this->assertNull($document->active_processing_run_id);
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $conversation = $user->conversations()->create(['title' => 'Deletion recovery']);
        $conversation->documents()->attach($document->id);
        $this->assertTrue(app(ConversationRuntimeDocumentService::class)->runtimeCapableFor($user, $conversation)->isEmpty());
        $this->get(route('documents.download', $document))->assertForbidden();
        Storage::disk('documents')->assertExists($document->file_path);
        $client->shouldReceive('deleteProcessingRunPoints')->twice();
        $this->delete(route('documents.destroy', $document))->assertSessionHas('success');
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }

    public function test_storage_failure_after_vectors_does_not_restore_active_run(): void
    {
        Storage::fake('documents');
        Storage::fake('document_quarantine');
        $user = User::factory()->create();
        [$document] = $this->readyDocument($user);
        $client = Mockery::mock(AiServiceClient::class);
        $client->shouldReceive('deleteProcessingRunPoints')->twice();
        $this->app->instance(AiServiceClient::class, $client);
        $storage = Mockery::mock(DocumentStorageService::class);
        $storage->shouldReceive('delete')->once()->andThrow(new \RuntimeException('Disk unavailable.'));
        $this->app->instance(DocumentStorageService::class, $storage);
        $this->actingAs($user)->delete(route('documents.destroy', $document))->assertSessionHas('error');
        $document->refresh();
        $this->assertNotNull($document->deletion_started_at);
        $this->assertNull($document->active_processing_run_id);
        $this->assertSame(DocumentStatus::Failed, $document->status);
    }

    /**
     * @return array{Document, ProcessingRun, ProcessingRun}
     */
    private function readyDocument(User $user): array
    {
        $document = app(DocumentStorageService::class)
            ->storePermanent(
                $user,
                UploadedFile::fake()->createWithContent(
                    'ready-document.txt',
                    "Ready document content.\n",
                ),
            );

        $oldRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Failed,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $activeRun = $document->processingRuns()->create([
            'profile' => ProcessingProfile::HybridLocal,
            'status' => ProcessingRunStatus::Indexed,
            'kind' => ProcessingRunKind::Reprocessing,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        $document->forceFill([
            'active_processing_run_id' => $activeRun->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        return [
            $document->fresh(),
            $oldRun,
            $activeRun,
        ];
    }
}
