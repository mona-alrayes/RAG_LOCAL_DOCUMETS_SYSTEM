<?php

namespace App\Services\Conversations;

use App\Enums\DocumentAvailability;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\Presentation\DocumentAvailabilityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Resolves the documents that are actually usable by RAG at runtime
 * for one authenticated user's conversation.
 *
 * Selection and runtime capability are intentionally separate concerns:
 * - conversation_document says what the user selected.
 * - this service says which selected documents are safe to use now.
 */
final class ConversationRuntimeDocumentService
{
    public function __construct(
        private readonly DocumentAvailabilityResolver $availabilityResolver,
    ) {}

    /**
     * @return Collection<int, Document>
     *
     * @throws AuthorizationException
     */
    public function runtimeCapableFor(
        User $user,
        Conversation $conversation,
    ): Collection {
        if ((int) $conversation->user_id !== (int) $user->id) {
            throw new AuthorizationException(
                'The conversation does not belong to the authenticated user.',
            );
        }

        return $conversation->documents()
            ->where('documents.user_id', $user->id)
            ->with('activeProcessingRun')
            ->get()
            ->filter(
                fn (Document $document): bool => $this
                    ->isRuntimeCapable($document),
            )
            ->values();
    }

    private function isRuntimeCapable(Document $document): bool
    {
        try {
            return $this->availabilityResolver->resolve($document)
                === DocumentAvailability::Ready;
        } catch (LogicException) {
            /*
             * Fail closed.
             *
             * A corrupt/missing/cross-document active processing run must
             * never make a document available to runtime retrieval.
             */
            return false;
        }
    }
}
