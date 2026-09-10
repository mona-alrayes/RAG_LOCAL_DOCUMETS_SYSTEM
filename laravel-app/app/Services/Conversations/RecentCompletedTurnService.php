<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RecentCompletedTurnService
{
    /**
     * @return Collection<int, array{user: string, assistant: string}>
     */
    public function forConversation(Conversation $conversation): Collection
    {
        $orderedMessages = $conversation->messages()
            ->select([
                'messages.id',
                'messages.role',
                'messages.status',
                'messages.content',
                'messages.created_at',

                DB::raw(
                    'LAG(messages.role) OVER (
                        ORDER BY messages.created_at ASC, messages.id ASC
                    ) AS previous_role'
                ),

                DB::raw(
                    'LAG(messages.status) OVER (
                        ORDER BY messages.created_at ASC, messages.id ASC
                    ) AS previous_status'
                ),

                DB::raw(
                    'LAG(messages.content) OVER (
                        ORDER BY messages.created_at ASC, messages.id ASC
                    ) AS previous_content'
                ),
            ])
            ->toBase();

        $turns = DB::query()
            ->fromSub($orderedMessages, 'ordered_messages')
            ->where(
                'role',
                MessageRole::Assistant->value,
            )
            ->where(
                'status',
                MessageStatus::Completed->value,
            )
            ->where(
                'previous_role',
                MessageRole::User->value,
            )
            ->where(
                'previous_status',
                MessageStatus::Completed->value,
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get([
                'previous_content',
                'content',
            ]);

        return $turns
            ->reverse()
            ->values()
            ->map(
                fn (object $turn): array => [
                    'user' => (string) $turn->previous_content,
                    'assistant' => (string) $turn->content,
                ],
            );
    }
}
