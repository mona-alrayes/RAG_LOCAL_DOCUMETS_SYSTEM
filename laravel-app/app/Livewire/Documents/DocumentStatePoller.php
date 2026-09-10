<?php

namespace App\Livewire\Documents;

use App\Models\User;
use App\Services\Documents\Presentation\DocumentUiSnapshotService;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class DocumentStatePoller extends Component
{
    private const SCOPES = [
        'workspace',
        'library',
        'document',
    ];

    #[Locked]
    public string $scope;

    #[Locked]
    public ?int $documentId = null;

    #[Locked]
    public string $pageUrl;

    #[Locked]
    public string $fingerprint;

    #[Locked]
    public bool $pollRequired = false;

    private DocumentUiSnapshotService $snapshotService;

    public function boot(
        DocumentUiSnapshotService $snapshotService,
    ): void {
        $this->snapshotService = $snapshotService;
    }

    public function mount(
        string $scope,
        ?int $documentId = null,
    ): void {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException(
                'Unsupported document polling scope.',
            );
        }

        if ($scope === 'document' && $documentId === null) {
            throw new InvalidArgumentException(
                'Document polling requires a document id.',
            );
        }

        $this->scope = $scope;
        $this->documentId = $documentId;
        $this->pageUrl = url()->full();

        $snapshot = $this->snapshot();

        $this->fingerprint = $snapshot['fingerprint'];
        $this->pollRequired = $snapshot['poll_required'];
    }

    public function poll(): void
    {
        $snapshot = $this->snapshot();

        $this->pollRequired = $snapshot['poll_required'];

        if (hash_equals(
            $this->fingerprint,
            $snapshot['fingerprint'],
        )) {
            return;
        }

        $this->fingerprint = $snapshot['fingerprint'];

        $this->dispatch(
            'rag-document-state-changed',
            url: $this->pageUrl,
        );
    }

    public function render()
    {
        return view(
            'livewire.documents.document-state-poller',
        );
    }

    /**
     * @return array{poll_required: bool, fingerprint: string}
     */
    private function snapshot(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        return match ($this->scope) {
            'workspace' => $this->snapshotService
                ->workspaceForUser($user),

            'library' => $this->snapshotService
                ->libraryForUser($user),

            'document' => $this->snapshotService
                ->documentForUser(
                    $user,
                    (int) $this->documentId,
                ),

            default => throw new InvalidArgumentException(
                'Unsupported document polling scope.',
            ),
        };
    }
}
