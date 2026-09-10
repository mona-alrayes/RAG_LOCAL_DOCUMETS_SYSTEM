<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\Exceptions\ConversationHasPendingAnswer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ConversationDeletionService
{
    public function delete(
        User $user,
        Conversation $conversation,
    ): void {
        DB::transaction(
            function () use (
                $user,
                $conversation,
            ): void {
                $lockedConversation =
                    Conversation::query()
                        ->whereKey(
                            $conversation->getKey(),
                        )
                        ->lockForUpdate()
                        ->first();

                if (
                    $lockedConversation === null
                    || (int) $lockedConversation->user_id
                        !== (int) $user->getKey()
                ) {
                    throw new AuthorizationException;
                }

                $hasPendingAnswer =
                    $lockedConversation
                        ->messages()
                        ->where(
                            'role',
                            MessageRole::Assistant->value,
                        )
                        ->where(
                            'status',
                            MessageStatus::Pending->value,
                        )
                        ->exists();

                if ($hasPendingAnswer) {
                    throw new ConversationHasPendingAnswer;
                }

                $lockedConversation->delete();
            },
            attempts: 3,
        );
    }
}
