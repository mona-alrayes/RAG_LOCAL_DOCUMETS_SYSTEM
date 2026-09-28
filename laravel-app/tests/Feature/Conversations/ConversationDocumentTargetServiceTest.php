<?php

namespace Tests\Feature\Conversations;

use App\Enums\DocumentStatus;
use App\Enums\FileType;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Conversations\ConversationDocumentTargetService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationDocumentTargetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_valid_document_produces_trusted_target(): void
    {
        $user = $this->verifiedUser();
        $conversation = $this->conversation($user);

        [$document, $run] = $this->readyDocument(
            $user,
            'single.pdf',
            ProcessingProfile::Cloud,
        );

        $conversation->documents()->attach($document->id);

        $targets = $this->service()->targetsFor($user, $conversation);

        $this->assertCount(1, $targets);

        $target = $targets->first();

        $this->assertSame($document->id, $target->documentId);
        $this->assertSame($run->id, $target->processingRunId);
        $this->assertSame(
            ProcessingProfile::Cloud,
            $target->processingProfile,
        );
    }

    public function test_multiple_valid_documents_produce_multiple_targets(): void
    {
        $user = $this->verifiedUser();
        $conversation = $this->conversation($user);

        [$firstDocument, $firstRun] = $this->readyDocument(
            $user,
            'first.pdf',
        );

        [$secondDocument, $secondRun] = $this->readyDocument(
            $user,
            'second.pdf',
        );

        $conversation->documents()->attach([
            $firstDocument->id,
            $secondDocument->id,
        ]);

        $targets = $this->service()->targetsFor($user, $conversation);

        $this->assertSame(
            [$firstDocument->id, $secondDocument->id],
            $targets->pluck('documentId')->all(),
        );

        $this->assertSame(
            [$firstRun->id, $secondRun->id],
            $targets->pluck('processingRunId')->all(),
        );
    }

    public function test_selected_unready_document_produces_no_target_without_mutating_selection(): void
    {
        $user = $this->verifiedUser();
        $conversation = $this->conversation($user);

        $document = $this->processingDocument(
            $user,
            'processing.pdf',
        );

        $conversation->documents()->attach($document->id);

        $targets = $this->service()->targetsFor($user, $conversation);

        $this->assertTrue($targets->isEmpty());

        $this->assertDatabaseHas('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $document->id,
        ]);
    }

    public function test_mixed_processing_profiles_are_preserved_in_targets(): void
    {
        $user = $this->verifiedUser();
        $conversation = $this->conversation($user);

        [$cloudDocument] = $this->readyDocument(
            $user,
            'cloud.pdf',
            ProcessingProfile::Cloud,
        );

        [$localDocument] = $this->readyDocument(
            $user,
            'local.pdf',
            ProcessingProfile::HybridLocal,
        );

        $conversation->documents()->attach([
            $cloudDocument->id,
            $localDocument->id,
        ]);

        $targets = $this->service()->targetsFor($user, $conversation);

        $profiles = $targets
            ->mapWithKeys(
                fn ($target): array => [
                    $target->documentId => $target->processingProfile,
                ],
            );

        $this->assertSame(
            ProcessingProfile::Cloud,
            $profiles[$cloudDocument->id],
        );

        $this->assertSame(
            ProcessingProfile::HybridLocal,
            $profiles[$localDocument->id],
        );
    }

    public function test_active_indexed_run_is_used_while_newer_reprocessing_is_in_progress(): void
    {
        $user = $this->verifiedUser();
        $conversation = $this->conversation($user);

        [$document, $activeRun] = $this->readyDocument(
            $user,
            'reprocessing.pdf',
            ProcessingProfile::Cloud,
        );

        $newerRun = $this->createRun(
            $document,
            ProcessingRunStatus::Processing,
            ProcessingProfile::HybridLocal,
            ProcessingRunKind::Reprocessing,
        );

        $conversation->documents()->attach($document->id);

        $target = $this->service()
            ->targetsFor($user, $conversation)
            ->sole();

        $this->assertSame($activeRun->id, $target->processingRunId);
        $this->assertNotSame($newerRun->id, $target->processingRunId);
        $this->assertSame(
            ProcessingProfile::Cloud,
            $target->processingProfile,
        );
    }

    public function test_active_indexed_run_is_used_when_newer_replacement_failed(): void
    {
        $user = $this->verifiedUser();
        $conversation = $this->conversation($user);

        [$document, $activeRun] = $this->readyDocument(
            $user,
            'failed-reprocessing.pdf',
            ProcessingProfile::Cloud,
        );

        $failedRun = $this->createRun(
            $document,
            ProcessingRunStatus::Failed,
            ProcessingProfile::HybridLocal,
            ProcessingRunKind::Reprocessing,
        );

        $conversation->documents()->attach($document->id);

        $target = $this->service()
            ->targetsFor($user, $conversation)
            ->sole();

        $this->assertSame($activeRun->id, $target->processingRunId);
        $this->assertNotSame($failedRun->id, $target->processingRunId);
    }

    public function test_foreign_conversation_is_rejected(): void
    {
        $user = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $this->conversation($otherUser);

        $this->expectException(AuthorizationException::class);

        $this->service()->targetsFor($user, $conversation);
    }

    public function test_corrupted_foreign_document_pivot_cannot_leak_target(): void
    {
        $owner = $this->verifiedUser();
        $otherUser = $this->verifiedUser();

        $conversation = $this->conversation($owner);

        [$ownedDocument] = $this->readyDocument(
            $owner,
            'owned.pdf',
        );

        [$foreignDocument] = $this->readyDocument(
            $otherUser,
            'foreign.pdf',
        );

        $conversation->documents()->attach($ownedDocument->id);

        DB::table('conversation_document')->insert([
            'conversation_id' => $conversation->id,
            'document_id' => $foreignDocument->id,
        ]);

        $targets = $this->service()->targetsFor(
            $owner,
            $conversation,
        );

        $this->assertSame(
            [$ownedDocument->id],
            $targets->pluck('documentId')->all(),
        );

        $this->assertNotContains(
            $foreignDocument->id,
            $targets->pluck('documentId')->all(),
        );
    }

    public function test_cross_document_active_run_cannot_produce_target(): void
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

        $foreignRun = $this->createRun(
            $otherDocument,
            ProcessingRunStatus::Indexed,
        );

        $document->forceFill([
            'active_processing_run_id' => $foreignRun->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        $conversation->documents()->attach($document->id);

        $targets = $this->service()->targetsFor(
            $user,
            $conversation,
        );

        $this->assertTrue($targets->isEmpty());
    }

    public function test_resolving_targets_does_not_mutate_pivot_document_or_processing_run(): void
    {
        $user = $this->verifiedUser();
        $conversation = $this->conversation($user);

        [$document, $run] = $this->readyDocument(
            $user,
            'immutable.pdf',
        );

        $conversation->documents()->attach($document->id);

        $documentBefore = $document->fresh()->getAttributes();
        $runBefore = $run->fresh()->getAttributes();

        $this->service()->targetsFor($user, $conversation);

        $this->assertDatabaseHas('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $document->id,
        ]);

        $this->assertSame(
            $documentBefore,
            $document->fresh()->getAttributes(),
        );

        $this->assertSame(
            $runBefore,
            $run->fresh()->getAttributes(),
        );
    }

    private function service(): ConversationDocumentTargetService
    {
        return app(ConversationDocumentTargetService::class);
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function conversation(User $user): Conversation
    {
        return $user->conversations()->create([
            'title' => 'محادثة اختبار K1',
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
        $document = $this->createDocument($user, $name);

        $document->forceFill([
            'status' => DocumentStatus::Processing,
        ])->save();

        $this->createRun(
            $document,
            ProcessingRunStatus::Processing,
        );

        return $document->fresh();
    }

    /**
     * @return array{Document, ProcessingRun}
     */
    private function readyDocument(
        User $user,
        string $name,
        ProcessingProfile $profile = ProcessingProfile::Cloud,
    ): array {
        $document = $this->createDocument($user, $name);

        $run = $this->createRun(
            $document,
            ProcessingRunStatus::Indexed,
            $profile,
        );

        $document->forceFill([
            'active_processing_run_id' => $run->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        return [$document->fresh(), $run->fresh()];
    }

    private function createRun(
        Document $document,
        ProcessingRunStatus $status,
        ProcessingProfile $profile = ProcessingProfile::Cloud,
        ProcessingRunKind $kind = ProcessingRunKind::Initial,
    ): ProcessingRun {
        return $document->processingRuns()->create([
            'profile' => $profile,
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
