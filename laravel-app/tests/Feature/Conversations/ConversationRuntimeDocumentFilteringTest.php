<?php

namespace Tests\Feature\Conversations;

use App\Enums\DocumentStatus;
use App\Enums\FileType;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Livewire\Conversations\DocumentSelector;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Conversations\ConversationRuntimeDocumentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ConversationRuntimeDocumentFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_selected_runtime_capable_documents_are_returned_without_mutating_selection(): void
    {
        $user = $this->verifiedUser();

        $conversation = $this->conversation($user);

        $ready = $this->readyDocument($user, 'ready.pdf');

        $processing = $this->processingDocument(
            $user,
            'processing.pdf',
        );

        $indexedWithoutActivation = $this->createDocument(
            $user,
            'indexed-without-activation.pdf',
        );

        $this->createRun(
            $indexedWithoutActivation,
            ProcessingRunStatus::Indexed,
        );

        $conversation->documents()->attach([
            $ready->id,
            $processing->id,
            $indexedWithoutActivation->id,
        ]);

        $documents = app(
            ConversationRuntimeDocumentService::class,
        )->runtimeCapableFor(
            $user,
            $conversation,
        );

        $this->assertSame(
            [$ready->id],
            $documents->modelKeys(),
        );

        $this->assertDatabaseCount(
            'conversation_document',
            3,
        );

        foreach ([
            $ready,
            $processing,
            $indexedWithoutActivation,
        ] as $document) {
            $this->assertDatabaseHas(
                'conversation_document',
                [
                    'conversation_id' => $conversation->id,
                    'document_id' => $document->id,
                ],
            );
        }
    }

    public function test_foreign_document_cannot_leak_even_if_pivot_is_corrupted(): void
    {
        $owner = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $this->conversation($owner);

        $ownedDocument = $this->readyDocument(
            $owner,
            'owned.pdf',
        );

        $foreignDocument = $this->readyDocument(
            $otherUser,
            'foreign.pdf',
        );

        $conversation->documents()->attach(
            $ownedDocument->id,
        );

        /*
         * Simulate corrupted legacy/application data.
         * The runtime service must still fail closed.
         */
        DB::table('conversation_document')->insert([
            'conversation_id' => $conversation->id,
            'document_id' => $foreignDocument->id,
        ]);

        $documents = app(
            ConversationRuntimeDocumentService::class,
        )->runtimeCapableFor(
            $owner,
            $conversation,
        );

        $this->assertSame(
            [$ownedDocument->id],
            $documents->modelKeys(),
        );

        $this->assertFalse(
            $documents->contains(
                fn (Document $document): bool => $document->id
                    === $foreignDocument->id,
            ),
        );
    }

    public function test_processing_run_belonging_to_another_document_cannot_make_document_runtime_capable(): void
    {
        $user = $this->verifiedUser();

        $conversation = $this->conversation($user);

        $document = $this->createDocument(
            $user,
            'current.pdf',
        );

        $otherDocument = $this->createDocument(
            $user,
            'other.pdf',
        );

        $otherRun = $this->createRun(
            $otherDocument,
            ProcessingRunStatus::Indexed,
        );

        $document->forceFill([
            'active_processing_run_id' => $otherRun->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        $conversation->documents()->attach(
            $document->id,
        );

        $documents = app(
            ConversationRuntimeDocumentService::class,
        )->runtimeCapableFor(
            $user,
            $conversation,
        );

        $this->assertTrue($documents->isEmpty());

        $this->assertDatabaseHas(
            'conversation_document',
            [
                'conversation_id' => $conversation->id,
                'document_id' => $document->id,
            ],
        );
    }

    public function test_valid_active_run_remains_runtime_capable_while_reprocessing_is_in_progress(): void
    {
        $user = $this->verifiedUser();

        $conversation = $this->conversation($user);

        $document = $this->readyDocument(
            $user,
            'reprocessing.pdf',
        );

        $this->createRun(
            $document,
            ProcessingRunStatus::Processing,
            ProcessingRunKind::Reprocessing,
        );

        $conversation->documents()->attach(
            $document->id,
        );

        $documents = app(
            ConversationRuntimeDocumentService::class,
        )->runtimeCapableFor(
            $user,
            $conversation,
        );

        $this->assertSame(
            [$document->id],
            $documents->modelKeys(),
        );
    }

    public function test_service_rejects_conversation_owned_by_another_user(): void
    {
        $owner = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $this->conversation($otherUser);

        $this->expectException(
            AuthorizationException::class,
        );

        app(
            ConversationRuntimeDocumentService::class,
        )->runtimeCapableFor(
            $owner,
            $conversation,
        );
    }

    public function test_unready_document_remains_selectable_and_attached(): void
    {
        $user = $this->verifiedUser();

        $conversation = $this->conversation($user);

        $document = $this->processingDocument(
            $user,
            'still-processing.pdf',
        );

        Livewire::actingAs($user)
            ->test(
                DocumentSelector::class,
                [
                    'conversationId' => $conversation->id,
                ],
            )
            ->set(
                'selectedDocumentIds',
                [(string) $document->id],
            )
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('جارٍ معالجة الوثيقة');

        $this->assertDatabaseHas(
            'conversation_document',
            [
                'conversation_id' => $conversation->id,
                'document_id' => $document->id,
            ],
        );

        $runtimeDocuments = app(
            ConversationRuntimeDocumentService::class,
        )->runtimeCapableFor(
            $user,
            $conversation->fresh(),
        );

        $this->assertTrue(
            $runtimeDocuments->isEmpty(),
        );
    }

    public function test_livewire_status_changes_to_ready_without_reselecting_document(): void
    {
        $user = $this->verifiedUser();

        $conversation = $this->conversation($user);

        $document = $this->processingDocument(
            $user,
            'dynamic.pdf',
        );

        $conversation->documents()->attach(
            $document->id,
        );

        $component = Livewire::actingAs($user)
            ->test(
                DocumentSelector::class,
                [
                    'conversationId' => $conversation->id,
                ],
            )
            ->assertSee('جارٍ معالجة الوثيقة')
            ->assertSeeHtml('wire:poll.visible.5s');

        $run = $document->latestAttempt()
            ->firstOrFail();

        $run->forceFill([
            'status' => ProcessingRunStatus::Indexed,
            'indexed_at' => now(),
        ])->save();

        $document->forceFill([
            'active_processing_run_id' => $run->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        $component
            ->call('refreshDocuments')
            ->assertSee('جاهزة للاستخدام');

        $this->assertDatabaseHas(
            'conversation_document',
            [
                'conversation_id' => $conversation->id,
                'document_id' => $document->id,
            ],
        );
    }

    public function test_conversation_selector_does_not_show_another_users_documents(): void
    {
        $user = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $this->conversation($user);

        $ownedDocument = $this->createDocument(
            $user,
            'owned-visible.pdf',
        );

        $foreignDocument = $this->createDocument(
            $otherUser,
            'foreign-hidden.pdf',
        );

        Livewire::actingAs($user)
            ->test(
                DocumentSelector::class,
                [
                    'conversationId' => $conversation->id,
                ],
            )
            ->assertSee($ownedDocument->original_name)
            ->assertDontSee($foreignDocument->original_name);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function conversation(
        User $user,
    ): Conversation {
        return $user->conversations()->create([
            'title' => 'محادثة اختبار J8',
        ]);
    }

    private function createDocument(
        User $user,
        string $name,
    ): Document {
        return $user->documents()->create([
            'original_name' => $name,
            'stored_name' => Str::uuid()->toString().'-'.$name,
            'title' => null,
            'file_path' => 'documents/'.Str::uuid()->toString(),
            'file_type' => FileType::Pdf,
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => hash(
                'sha256',
                Str::uuid()->toString().$name,
            ),
        ]);
    }

    private function processingDocument(
        User $user,
        string $name,
    ): Document {
        $document = $this->createDocument(
            $user,
            $name,
        );

        $document->forceFill([
            'status' => DocumentStatus::Processing,
        ])->save();

        $this->createRun(
            $document,
            ProcessingRunStatus::Processing,
        );

        return $document->fresh();
    }

    private function readyDocument(
        User $user,
        string $name,
    ): Document {
        $document = $this->createDocument(
            $user,
            $name,
        );

        $run = $this->createRun(
            $document,
            ProcessingRunStatus::Indexed,
        );

        $document->forceFill([
            'active_processing_run_id' => $run->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        return $document->fresh();
    }

    private function createRun(
        Document $document,
        ProcessingRunStatus $status,
        ProcessingRunKind $kind = ProcessingRunKind::Initial,
    ): ProcessingRun {
        return $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => $status,
            'kind' => $kind,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'indexed_at' => $status === ProcessingRunStatus::Indexed
                ? now()
                : null,
        ]);
    }
}
