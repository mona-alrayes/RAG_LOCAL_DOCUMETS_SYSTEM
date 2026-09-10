<?php

namespace App\Services\Conversations\Presentation;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ConversationReadService
{
    /**
     * @return Collection<int, Conversation>
     */
    public function forUser(
        User $user,
    ): Collection {
        return $user->conversations()
            ->select([
                'id',
                'user_id',
                'title',
                'created_at',
            ])
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, Message>
     */
    public function messagesForConversation(
        Conversation $conversation,
        User $user,
    ): Collection {
        return $conversation->messages()
            ->select([
                'id',
                'conversation_id',
                'role',
                'status',
                'content',
                'metrics',
                'created_at',
            ])
            ->with([
                'sources' => function (
                    $query,
                ) use ($user): void {
                    $query
                        ->select([
                            'id',
                            'message_id',
                            'processing_run_id',
                            'chunk_index',
                            'source_snapshot',
                            'relevance_score',
                            'reranker_score',
                        ])
                        ->whereHas(
                            'processingRun.document',
                            fn ($query) => $query->where(
                                'user_id',
                                $user->getKey(),
                            ),
                        )
                        ->with([
                            'processingRun:id,document_id,profile',
                            'processingRun.document:id,user_id,title,original_name',
                        ])
                        ->orderBy('id');
                },
            ])
            ->oldest('created_at')
            ->oldest('id')
            ->get();
    }
}
