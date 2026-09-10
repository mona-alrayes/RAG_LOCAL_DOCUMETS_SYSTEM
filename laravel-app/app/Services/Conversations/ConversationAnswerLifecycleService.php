<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Jobs\AskConversationJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\Streaming\ConversationAnswerStreamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class ConversationAnswerLifecycleService
{
    public const FAILURE_MESSAGE = 'تعذر إنشاء الإجابة.';

    public function __construct(
        private readonly ConversationAnswerStreamService $streamService,
    ) {}

    public function start(
        User $user,
        Conversation $conversation,
        string $question,
    ): Message {
        $this->assertOwnedConversation($user, $conversation);

        $question = trim($question);

        if ($question === '') {
            throw new LogicException(
                'Conversation question cannot be empty.',
            );
        }

        [$userMessage, $assistantMessage] = DB::transaction(
            function () use (
                $user,
                $conversation,
                $question,
            ): array {
                $lockedConversation =
                    $this->lockOwnedConversation(
                        $user,
                        $conversation,
                    );

                $userMessage = $lockedConversation->messages()->create([
                    'role' => MessageRole::User,
                    'status' => MessageStatus::Completed,
                    'content' => $question,
                ]);

                $assistantMessage =
                    $lockedConversation->messages()->create([
                        'role' => MessageRole::Assistant,
                        'status' => MessageStatus::Pending,
                        'content' => null,
                        'execution_snapshot' => [
                            'question_message_id' => (int) $userMessage->getKey(),
                        ],
                        'metrics' => null,
                    ]);

                return [
                    $userMessage,
                    $assistantMessage,
                ];
            },
        );

        try {
            $this->dispatch(
                user: $user,
                conversation: $conversation,
                question: $userMessage,
                assistant: $assistantMessage,
            );
        } catch (Throwable $exception) {
            $this->failPending($assistantMessage);

            throw $exception;
        }

        return $assistantMessage;
    }

    public function retry(
        User $user,
        Conversation $conversation,
        int $assistantMessageId,
    ): Message {
        $this->assertOwnedConversation(
            $user,
            $conversation,
        );

        [$assistant, $question] = DB::transaction(
            function () use (
                $user,
                $conversation,
                $assistantMessageId,
            ): array {
                $lockedConversation =
                    $this->lockOwnedConversation(
                        $user,
                        $conversation,
                    );

                $assistant = Message::query()
                    ->whereKey($assistantMessageId)
                    ->where(
                        'conversation_id',
                        $lockedConversation->getKey(),
                    )
                    ->where(
                        'role',
                        MessageRole::Assistant->value,
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $assistant->status
                    !== MessageStatus::Failed
                ) {
                    throw new LogicException(
                        'Only a failed assistant answer may be retried.',
                    );
                }

                $questionId =
                    $this->questionId($assistant);

                $question = Message::query()
                    ->whereKey($questionId)
                    ->where(
                        'conversation_id',
                        $lockedConversation->getKey(),
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertQuestion($question);

                /*
                 * Clear the previous ephemeral attempt before
                 * reopening this same answer as pending.
                 */
                $this->streamService->reset(
                    (int) $assistant->getKey(),
                );

                $assistant->sources()->delete();

                $assistant->forceFill([
                    'status' => MessageStatus::Pending,
                    'content' => null,
                    'execution_snapshot' => [
                        'question_message_id' => (int) $question->getKey(),
                    ],
                    'metrics' => null,
                ])->save();

                return [
                    $assistant->fresh(),
                    $question,
                ];
            },
        );

        try {
            $this->dispatch(
                user: $user,
                conversation: $conversation,
                question: $question,
                assistant: $assistant,
            );
        } catch (Throwable $exception) {
            $this->failPending($assistant);

            throw $exception;
        }

        return $assistant;
    }

    public function failPending(
        Message $assistant,
    ): Message {
        return DB::transaction(
            function () use ($assistant): Message {
                $lockedAssistant = Message::query()
                    ->whereKey($assistant->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedAssistant->role
                        !== MessageRole::Assistant
                    || $lockedAssistant->status
                        !== MessageStatus::Pending
                ) {
                    return $lockedAssistant;
                }

                $lockedAssistant->sources()->delete();

                $lockedAssistant->forceFill([
                    'status' => MessageStatus::Failed,
                    'content' => self::FAILURE_MESSAGE,
                    'metrics' => null,
                ])->save();

                return $lockedAssistant->fresh();
            },
        );
    }

    private function lockOwnedConversation(
        User $user,
        Conversation $conversation,
    ): Conversation {
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

        return $lockedConversation;
    }

    private function dispatch(
        User $user,
        Conversation $conversation,
        Message $question,
        Message $assistant,
    ): void {
        AskConversationJob::dispatch(
            userId: (int) $user->getKey(),
            conversationId: (int) $conversation->getKey(),
            userMessageId: (int) $question->getKey(),
            assistantMessageId: (int) $assistant->getKey(),
        );
    }

    private function assertOwnedConversation(
        User $user,
        Conversation $conversation,
    ): void {
        if (
            (int) $conversation->user_id
            !== (int) $user->getKey()
        ) {
            throw new AuthorizationException;
        }
    }

    private function questionId(
        Message $assistant,
    ): int {
        $snapshot = $assistant->execution_snapshot;

        $questionId = is_array($snapshot)
            ? (
                $snapshot['question_message_id']
                ?? null
            )
            : null;

        if (
            ! is_int($questionId)
            || $questionId < 1
        ) {
            throw new LogicException(
                'Failed answer is not bound to a valid question.',
            );
        }

        return $questionId;
    }

    private function assertQuestion(
        Message $question,
    ): void {
        if (
            $question->role !== MessageRole::User
            || $question->status
                !== MessageStatus::Completed
            || ! is_string($question->content)
            || trim($question->content) === ''
        ) {
            throw new LogicException(
                'Conversation question is not available for retry.',
            );
        }
    }
}
