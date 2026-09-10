<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Services\Conversations\Data\TrustedDocumentTarget;
use Illuminate\Support\Collection;

final class ConversationDocumentTargetService
{
    public function __construct(
        private readonly ConversationRuntimeDocumentService $runtimeDocumentService,
    ) {}

    /**
     * @return Collection<int, TrustedDocumentTarget>
     */
    public function targetsFor(
        User $user,
        Conversation $conversation,
    ): Collection {
        return $this->runtimeDocumentService
            ->runtimeCapableFor($user, $conversation)
            ->map(
                function (Document $document): ?TrustedDocumentTarget {
                    if (! $document->relationLoaded('activeProcessingRun')) {
                        return null;
                    }

                    $activeRun = $document->activeProcessingRun;

                    if ($activeRun === null) {
                        return null;
                    }

                    return new TrustedDocumentTarget(
                        documentId: (int) $document->id,
                        processingRunId: (int) $activeRun->id,
                        processingProfile: $activeRun->profile,
                    );
                },
            )
            ->filter(
                fn (?TrustedDocumentTarget $target): bool => $target !== null,
            )
            ->values();
    }
}
