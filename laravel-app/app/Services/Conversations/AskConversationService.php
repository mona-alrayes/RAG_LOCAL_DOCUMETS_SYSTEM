<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Exceptions\AiServiceException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AiServiceClient;
use App\Services\Ai\Data\RagCompletedEventData;
use App\Services\Ai\Data\RagQueryRequestData;
use App\Services\Ai\Data\RagTokenEventData;
use App\Services\Conversations\Streaming\ConversationAnswerStreamService;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final class AskConversationService
{
    public function __construct(
        private readonly ConversationDocumentTargetService $documentTargetService,
        private readonly RecentCompletedTurnService $recentCompletedTurnService,
        private readonly AiServiceClient $aiServiceClient,
        private readonly CompletedRagAnswerPersistenceService $answerPersistenceService,
        private readonly ConversationAnswerLifecycleService $answerLifecycleService,
        private readonly ConversationAnswerStreamService $streamService,
    ) {}

    public function execute(
        int $userId,
        int $conversationId,
        int $userMessageId,
        int $assistantMessageId,
    ): void {
        $user = User::query()->findOrFail(
            $userId,
        );

        $conversation = Conversation::query()
            ->whereKey($conversationId)
            ->where(
                'user_id',
                $user->getKey(),
            )
            ->firstOrFail();

        $question = Message::query()
            ->whereKey($userMessageId)
            ->where(
                'conversation_id',
                $conversation->getKey(),
            )
            ->firstOrFail();

        $assistant = Message::query()
            ->whereKey($assistantMessageId)
            ->where(
                'conversation_id',
                $conversation->getKey(),
            )
            ->firstOrFail();

        $this->assertTrustedQuestion($question);

        $this->assertTrustedAssistant(
            assistant: $assistant,
            question: $question,
        );

        if (
            $assistant->status
                === MessageStatus::Completed
            || $assistant->status
                === MessageStatus::Failed
        ) {
            return;
        }

        if (
            $assistant->status
            !== MessageStatus::Pending
        ) {
            throw new LogicException(
                'Assistant answer is not available for generation.',
            );
        }

        /*
         * Runtime targets are rebuilt here on the server.
         * The browser does not supply provider/profile/run data.
         */
        $targets = $this
            ->documentTargetService
            ->targetsFor(
                $user,
                $conversation,
            );

        $recentTurns = $this
            ->recentCompletedTurnService
            ->forConversation($conversation);

        $request = new RagQueryRequestData(
            userId: (int) $user->getKey(),
            question: $question->content,
            documentTargets: $targets
                ->map(
                    fn ($target): array => [
                        'document_id' => $target->documentId,
                        'processing_run_id' => $target->processingRunId,
                        'processing_profile' => $target
                            ->processingProfile
                            ->value,
                    ],
                )
                ->values()
                ->all(),
            recentCompletedTurns: $recentTurns->all(),
        );

        $completedEvent = null;

        try {
            foreach (
                $this->aiServiceClient
                    ->streamRagQuery($request) as $event
            ) {
                if ($completedEvent !== null) {
                    throw new AiServiceException(
                        message: 'AI service returned events after answer completion.',
                    );
                }

                if ($event instanceof RagTokenEventData) {
                    if ($event->content !== '') {
                        $this->streamService
                            ->publishToken(
                                assistantMessageId: (int) $assistant
                                    ->getKey(),
                                content: $event->content,
                            );
                    }

                    continue;
                }

                if ($event instanceof RagCompletedEventData) {
                    $completedEvent = $event;

                    continue;
                }

                throw new AiServiceException(
                    message: 'AI service returned an unsupported answer event.',
                );
            }

            if ($completedEvent === null) {
                throw new AiServiceException(
                    message: 'AI service answer stream ended without completion.',
                );
            }

            $this->answerPersistenceService
                ->persist(
                    conversation: $conversation,
                    question: $question,
                    assistant: $assistant,
                    request: $request,
                    completedEvent: $completedEvent,
                );
        } catch (Throwable $exception) {
            try {
                $this->answerLifecycleService
                    ->failPending($assistant);
            } catch (
                Throwable $failurePersistenceException
            ) {
                Log::error(
                    'Failed to persist safe conversation answer failure state.',
                    [
                        'assistant_message_id' => $assistant->getKey(),
                        'exception' => $failurePersistenceException,
                    ],
                );
            }

            try {
                $this->streamService
                    ->publishFailed(
                        (int) $assistant->getKey(),
                    );
            } catch (Throwable $streamException) {
                Log::warning(
                    'Failed to publish conversation answer failure event.',
                    [
                        'assistant_message_id' => $assistant->getKey(),
                        'exception' => $streamException,
                    ],
                );
            }

            throw $exception;
        }

        /*
         * MySQL is already authoritative at this point.
         * Transport failure must not undo a completed answer.
         */
        try {
            $this->streamService
                ->publishCompleted(
                    (int) $assistant->getKey(),
                );
        } catch (Throwable $streamException) {
            Log::warning(
                'Failed to publish conversation answer completion event.',
                [
                    'assistant_message_id' => $assistant->getKey(),
                    'exception' => $streamException,
                ],
            );
        }
    }

    private function assertTrustedQuestion(
        Message $message,
    ): void {
        if (
            $message->role !== MessageRole::User
            || $message->status
                !== MessageStatus::Completed
            || ! is_string($message->content)
            || trim($message->content) === ''
        ) {
            throw new LogicException(
                'Conversation question is not available for generation.',
            );
        }
    }

    private function assertTrustedAssistant(
        Message $assistant,
        Message $question,
    ): void {
        $snapshot =
            $assistant->execution_snapshot;

        $questionMessageId =
            is_array($snapshot)
                ? (
                    $snapshot[
                        'question_message_id'
                    ] ?? null
                )
                : null;

        if (
            $assistant->role
                !== MessageRole::Assistant
            || ! is_int($questionMessageId)
            || $questionMessageId
                !== (int) $question->getKey()
        ) {
            throw new LogicException(
                'Assistant answer is not bound to the requested question.',
            );
        }
    }
}
