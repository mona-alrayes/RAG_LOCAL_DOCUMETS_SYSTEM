<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\FileType;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Livewire\Documents\DocumentStatePoller;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentLivewirePollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_uses_livewire_polling_while_document_state_is_moving(): void
    {
        $user = $this->verifiedUser();

        $this->processingDocument($user, 'workspace-processing.pdf');

        $this
            ->actingAs($user)
            ->get(route('workspace'))
            ->assertOk()
            ->assertSeeHtml('wire:poll.5s="poll"');
    }

    public function test_document_library_uses_livewire_polling(): void
    {
        $user = $this->verifiedUser();

        $this->processingDocument($user, 'library-processing.pdf');

        $this
            ->actingAs($user)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSeeHtml('wire:poll.5s="poll"')
            ->assertSee('library-processing.pdf');
    }

    public function test_document_detail_uses_livewire_polling_instead_of_browser_reload_polling(): void
    {
        $user = $this->verifiedUser();

        $document = $this->processingDocument($user, 'detail-processing.pdf');

        $this
            ->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSeeHtml('wire:poll.5s="poll"')
            ->assertDontSee('data-document-poll-url', false);
    }

    public function test_sidebar_polls_on_non_document_pages_when_recent_document_is_processing(): void
    {
        $user = $this->verifiedUser();

        $this->processingDocument($user, 'sidebar-processing.pdf');

        $this
            ->actingAs($user)
            ->get(route('settings.account'))
            ->assertOk()
            ->assertSee('sidebar-processing.pdf')
            ->assertSeeHtml(
                'wire:poll.visible.10s="refreshDocuments"',
            );
    }

    public function test_document_state_poller_dispatches_refresh_when_snapshot_changes(): void
    {
        $user = $this->verifiedUser();

        $document = $this->processingDocument($user, 'changing-state.pdf');

        $component = Livewire::actingAs($user)
            ->test(
                DocumentStatePoller::class,
                [
                    'scope' => 'document',
                    'documentId' => $document->id,
                ],
            );

        $run = $document->latestAttempt()->firstOrFail();

        $run->forceFill([
            'status' => ProcessingRunStatus::Indexing,
            'indexing_started_at' => now(),
        ])->save();

        $document->forceFill([
            'status' => DocumentStatus::Indexing,
        ])->save();

        $component
            ->call('poll')
            ->assertDispatched('rag-document-state-changed');
    }

    public function test_ready_document_does_not_keep_document_page_polling_active(): void
    {
        $user = $this->verifiedUser();

        $document = $this->readyDocument($user, 'ready-document.pdf');

        Livewire::actingAs($user)
            ->test(
                DocumentStatePoller::class,
                [
                    'scope' => 'document',
                    'documentId' => $document->id,
                ],
            )
            ->assertSet('pollRequired', false)
            ->assertDontSeeHtml('wire:poll.5s="poll"');
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
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

        $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Processing,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'started_at' => now(),
        ]);

        return $document->fresh();
    }

    private function readyDocument(
        User $user,
        string $name,
    ): Document {
        $document = $this->createDocument($user, $name);

        $run = $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Indexed,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'indexing_started_at' => now()->subMinute(),
            'indexed_at' => now(),
        ]);

        $document->forceFill([
            'active_processing_run_id' => $run->id,
            'status' => DocumentStatus::Ready,
        ])->save();

        return $document->fresh();
    }
}
