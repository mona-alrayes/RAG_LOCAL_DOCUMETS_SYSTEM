<?php

namespace App\Livewire\Conversations;

use App\Models\Conversation;
use App\Models\Document;
use App\Services\Documents\Presentation\DocumentSummaryMapper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class DocumentSelector extends Component
{
    #[Locked]
    public int $conversationId;

    /**
     * @var list<string>
     */
    public array $selectedDocumentIds = [];

    public bool $saved = false;

    private DocumentSummaryMapper $summaryMapper;

    public function boot(
        DocumentSummaryMapper $summaryMapper,
    ): void {
        $this->summaryMapper = $summaryMapper;
    }

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;

        $conversation = $this->ownedConversation();

        Gate::authorize('view', $conversation);

        $this->selectedDocumentIds = $conversation->documents()
            ->where('documents.user_id', Auth::id())
            ->pluck('documents.id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    public function updatedSelectedDocumentIds(): void
    {
        $this->saved = false;
    }

    /**
     * Explicit polling action.
     *
     * Livewire automatically re-renders the component after the action.
     * No mutation is intentionally performed here.
     */
    public function refreshDocuments(): void
    {
        //
    }

    public function save(): void
    {
        $conversation = $this->ownedConversation();

        Gate::authorize('update', $conversation);

        $validated = Validator::make(
            [
                'document_ids' => $this->selectedDocumentIds,
            ],
            [
                'document_ids' => [
                    'array',
                ],
                'document_ids.*' => [
                    'integer',
                    'distinct',
                    Rule::exists('documents', 'id')
                        ->where(
                            fn ($query) => $query->where(
                                'user_id',
                                Auth::id(),
                            ),
                        ),
                ],
            ],
        )->validate();

        $documentIds = collect(
            $validated['document_ids'] ?? [],
        )
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $conversation->documents()->sync($documentIds);

        $this->selectedDocumentIds = collect($documentIds)
            ->map(fn (int $id): string => (string) $id)
            ->all();

        $this->saved = true;
    }

    public function render(): View
    {
        $conversation = $this->ownedConversation();

        Gate::authorize('view', $conversation);

        $documents = Auth::user()
            ->documents()
            ->with([
                'activeProcessingRun',
                'latestAttempt',
            ])
            ->latest()
            ->get()
            ->map(
                fn (Document $document) => $this
                    ->summaryMapper
                    ->map($document),
            )
            ->values();

        $persistedDocumentIds = $conversation->documents()
            ->where('documents.user_id', Auth::id())
            ->pluck('documents.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $selectedDocuments = $documents
            ->filter(
                fn ($document): bool => in_array(
                    $document->id,
                    $persistedDocumentIds,
                    true,
                ),
            )
            ->values();

        $pollRequired = $documents->contains(
            fn ($document): bool => $document->pollRequired,
        );

        return view(
            'livewire.conversations.document-selector',
            [
                'conversation' => $conversation,
                'documents' => $documents,
                'selectedDocuments' => $selectedDocuments,
                'pollRequired' => $pollRequired,
            ],
        );
    }

    private function ownedConversation(): Conversation
    {
        return Auth::user()
            ->conversations()
            ->findOrFail($this->conversationId);
    }
}
