<?php

namespace App\Services\Documents\Presentation;

use App\Models\Document;
use App\Models\User;

/**
 * Builds presentation-only snapshots used by Livewire polling.
 *
 * The service deliberately reuses the existing H12 read model and mapper,
 * so the browser never reinterprets document/run lifecycle rules.
 */
final class DocumentUiSnapshotService
{
    public function __construct(
        private readonly DocumentReadService $documentReadService,
        private readonly DocumentSummaryMapper $summaryMapper,
    ) {}

    /**
     * @return array{poll_required: bool, fingerprint: string}
     */
    public function workspaceForUser(User $user): array
    {
        $dashboard = $this->documentReadService->dashboardForUser($user);

        return $this->snapshot(
            pollRequired: $dashboard['active_processing_count'] > 0
                || $dashboard['reprocessing_count'] > 0,
            payload: $dashboard,
        );
    }

    /**
     * @return array{poll_required: bool, fingerprint: string}
     */
    public function libraryForUser(User $user): array
    {
        $summaries = $user->documents()
            ->with([
                'activeProcessingRun',
                'latestAttempt',
            ])
            ->orderBy('id')
            ->get()
            ->map(
                fn (Document $document) => $this
                    ->summaryMapper
                    ->map($document),
            )
            ->values();

        return $this->snapshot(
            pollRequired: $summaries->contains(
                fn ($document): bool => $document->pollRequired,
            ),
            payload: $summaries->all(),
        );
    }

    /**
     * @return array{poll_required: bool, fingerprint: string}
     */
    public function documentForUser(
        User $user,
        int $documentId,
    ): array {
        $detail = $this->documentReadService->detailForUser(
            $user,
            $documentId,
        );

        return $this->snapshot(
            pollRequired: $detail->summary->pollRequired,
            payload: $detail,
        );
    }

    /**
     * @return array{poll_required: bool, fingerprint: string}
     */
    private function snapshot(
        bool $pollRequired,
        mixed $payload,
    ): array {
        return [
            'poll_required' => $pollRequired,
            'fingerprint' => hash(
                'sha256',
                serialize($payload),
            ),
        ];
    }
}
