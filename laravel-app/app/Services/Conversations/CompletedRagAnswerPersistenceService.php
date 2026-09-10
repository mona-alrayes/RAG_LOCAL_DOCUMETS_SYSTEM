<?php

namespace App\Services\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Exceptions\AiServiceException;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\Data\RagCompletedEventData;
use App\Services\Ai\Data\RagQueryRequestData;
use App\Services\Ai\Data\RagSourceData;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CompletedRagAnswerPersistenceService
{
    public function alreadyPersisted(
        Conversation $conversation,
        Message $question,
    ): bool {
        return $conversation->messages()
            ->where(
                'role',
                MessageRole::Assistant->value,
            )
            ->where(
                'status',
                MessageStatus::Completed->value,
            )
            ->where(
                'execution_snapshot->question_message_id',
                (int) $question->getKey(),
            )
            ->exists();
    }

    public function persist(
        Conversation $conversation,
        Message $question,
        Message $assistant,
        RagQueryRequestData $request,
        RagCompletedEventData $completedEvent,
    ): Message {
        $sources = $this->normalizeSources(
            sources: $completedEvent->sources,
            request: $request,
        );

        $executionSnapshot = [
            'question_message_id' => (int) $question->getKey(),
            'document_targets' => $request->documentTargets,
            'recent_completed_turns' => $request->recentCompletedTurns,
        ];

        return DB::transaction(
            function () use (
                $conversation,
                $question,
                $assistant,
                $completedEvent,
                $sources,
                $executionSnapshot,
            ): Message {
                $lockedConversation =
                    Conversation::query()
                        ->whereKey(
                            $conversation->getKey(),
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $lockedQuestion =
                    Message::query()
                        ->whereKey(
                            $question->getKey(),
                        )
                        ->where(
                            'conversation_id',
                            $lockedConversation
                                ->getKey(),
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $this->assertPersistableQuestion(
                    $lockedQuestion,
                );

                $lockedAssistant =
                    Message::query()
                        ->whereKey(
                            $assistant->getKey(),
                        )
                        ->where(
                            'conversation_id',
                            $lockedConversation
                                ->getKey(),
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $this->assertPersistableAssistant(
                    assistant: $lockedAssistant,
                    question: $lockedQuestion,
                );

                if (
                    $lockedAssistant->status
                    === MessageStatus::Completed
                ) {
                    return $lockedAssistant
                        ->load('sources');
                }

                if (
                    $lockedAssistant->status
                    !== MessageStatus::Pending
                ) {
                    throw new LogicException(
                        'Assistant answer is not pending persistence.',
                    );
                }

                $lockedAssistant
                    ->sources()
                    ->delete();

                $lockedAssistant->forceFill([
                    'status' => MessageStatus::Completed,
                    'content' => $completedEvent->answer,
                    'execution_snapshot' => $executionSnapshot,
                    'metrics' => $completedEvent
                        ->timings
                        ->toArray(),
                ])->save();

                foreach ($sources as $source) {
                    $lockedAssistant
                        ->sources()
                        ->create($source);
                }

                return $lockedAssistant
                    ->load('sources');
            },
        );
    }

    /**
     * @param  list<RagSourceData>  $sources
     * @return list<array<string, mixed>>
     */
    private function normalizeSources(
        array $sources,
        RagQueryRequestData $request,
    ): array {
        $trustedTargets =
            $this->trustedTargetKeys(
                $request,
            );

        $normalized = [];
        $seenSources = [];

        foreach ($sources as $source) {
            $targetKey = $this->targetKey(
                documentId: $source->documentId,
                processingRunId: $source->processingRunId,
                processingProfile: $source
                    ->processingProfile
                    ->value,
            );

            if (
                ! isset(
                    $trustedTargets[
                        $targetKey
                    ]
                )
            ) {
                $this->invalidCompletedEvent();
            }

            $sourceKey = implode(':', [
                $source
                    ->processingProfile
                    ->value,
                $source->documentId,
                $source->processingRunId,
                $source->pointId,
            ]);

            if (
                isset(
                    $seenSources[$sourceKey]
                )
            ) {
                $this->invalidCompletedEvent();
            }

            $seenSources[$sourceKey] = true;

            $normalized[] = [
                'processing_run_id' => $source->processingRunId,
                'qdrant_point_id' => $source->pointId,
                'chunk_index' => $source->chunkIndex,
                'source_snapshot' => [
                    'source' => $source->source,
                    'page' => $source->page,
                    'section' => $source->section,
                    'excerpt' => $source->text,
                    'retrieval_score' => $source
                        ->retrievalScore,
                    'processing_profile' => $source
                        ->processingProfile
                        ->value,
                ],
                /*
                 * Legacy field kept unset because its
                 * historical meaning was ambiguous.
                 */
                'relevance_score' => null,
                'reranker_score' => $source->rerankerScore,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, true>
     */
    private function trustedTargetKeys(
        RagQueryRequestData $request,
    ): array {
        $targets = [];

        foreach (
            $request->documentTargets as $target
        ) {
            if (
                ! is_array($target)
                || ! is_int(
                    $target[
                        'document_id'
                    ] ?? null,
                )
                || $target[
                    'document_id'
                ] < 1
                || ! is_int(
                    $target[
                        'processing_run_id'
                    ] ?? null,
                )
                || $target[
                    'processing_run_id'
                ] < 1
                || ! is_string(
                    $target[
                        'processing_profile'
                    ] ?? null,
                )
                || trim(
                    $target[
                        'processing_profile'
                    ],
                ) === ''
            ) {
                throw new LogicException(
                    'Trusted RAG document target is invalid.',
                );
            }

            $targets[
                $this->targetKey(
                    documentId: $target[
                            'document_id'
                        ],
                    processingRunId: $target[
                            'processing_run_id'
                        ],
                    processingProfile: $target[
                            'processing_profile'
                        ],
                )
            ] = true;
        }

        return $targets;
    }

    private function assertPersistableQuestion(
        Message $question,
    ): void {
        if (
            $question->role
                !== MessageRole::User
            || $question->status
                !== MessageStatus::Completed
            || ! is_string(
                $question->content,
            )
            || trim(
                $question->content,
            ) === ''
        ) {
            throw new LogicException(
                'Conversation question is not available for answer persistence.',
            );
        }
    }

    private function assertPersistableAssistant(
        Message $assistant,
        Message $question,
    ): void {
        $snapshot =
            $assistant
                ->execution_snapshot;

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
            || ! is_int(
                $questionMessageId,
            )
            || $questionMessageId
                !== (int) $question
                    ->getKey()
        ) {
            throw new LogicException(
                'Assistant answer is not bound to the persisted question.',
            );
        }
    }

    private function targetKey(
        int $documentId,
        int $processingRunId,
        string $processingProfile,
    ): string {
        return implode(':', [
            $documentId,
            $processingRunId,
            $processingProfile,
        ]);
    }

    private function invalidCompletedEvent(): never
    {
        throw new AiServiceException(
            message: 'AI service returned an invalid completed answer event.',
        );
    }
}
