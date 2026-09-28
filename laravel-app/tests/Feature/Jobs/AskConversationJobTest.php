<?php

namespace Tests\Feature\Jobs;

use App\Enums\DocumentStatus;
use App\Enums\FileType;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use App\Exceptions\AiServiceException;
use App\Jobs\AskConversationJob;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\ProcessingRun;
use App\Models\User;
use App\Services\Ai\AiServiceClient;
use App\Services\Ai\Data\RagCompletedEventData;
use App\Services\Ai\Data\RagQueryRequestData;
use App\Services\Ai\Data\RagSourceData;
use App\Services\Ai\Data\RagTimingsData;
use App\Services\Ai\Data\RagTokenEventData;
use App\Services\Conversations\AskConversationService;
use App\Services\Conversations\ConversationAnswerLifecycleService;
use App\Services\Conversations\Streaming\ConversationAnswerStreamService;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AskConversationJobTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $streamService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->streamService = Mockery::mock(
            ConversationAnswerStreamService::class,
        );

        $this->streamService
            ->shouldReceive('publishToken')
            ->byDefault();

        $this->streamService
            ->shouldReceive('publishCompleted')
            ->byDefault();

        $this->streamService
            ->shouldReceive('publishFailed')
            ->byDefault();

        $this->streamService
            ->shouldReceive('reset')
            ->byDefault();

        $this->app->instance(
            ConversationAnswerStreamService::class,
            $this->streamService,
        );
    }

    public function test_job_persists_completed_answer_snapshot_metrics_and_trusted_sources(): void
    {
        [$user, $conversation, $question] = $this->conversationWithQuestion();

        [$readyDocument, $readyRun] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        $unreadyDocument = $this->unreadyDocument($user);

        $conversation->documents()->attach([
            $readyDocument->id,
            $unreadyDocument->id,
        ]);

        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('streamRagQuery')
            ->once()
            ->andReturnUsing(
                function (RagQueryRequestData $data) use (
                    $user,
                    $question,
                    $readyDocument,
                    $readyRun,
                    $unreadyDocument,
                ): Generator {
                    $this->assertSame($user->id, $data->userId);
                    $this->assertSame($question->content, $data->question);
                    $this->assertCount(1, $data->documentTargets);

                    $this->assertSame(
                        $readyDocument->id,
                        $data->documentTargets[0]['document_id'],
                    );

                    $this->assertSame(
                        $readyRun->id,
                        $data->documentTargets[0]['processing_run_id'],
                    );

                    $this->assertSame(
                        ProcessingProfile::Cloud->value,
                        $data->documentTargets[0]['processing_profile'],
                    );

                    $this->assertNotContains(
                        $unreadyDocument->id,
                        array_column(
                            $data->documentTargets,
                            'document_id',
                        ),
                    );

                    yield new RagTokenEventData(
                        content: 'جواب',
                    );

                    yield $this->completedEvent(
                        document: $readyDocument,
                        run: $readyRun,
                    );
                },
            );

        $this->app->instance(AiServiceClient::class, $client);

        $pendingAssistant = $conversation->messages()
            ->where(
                'role',
                MessageRole::Assistant->value,
            )
            ->sole();

        $this->streamService
            ->shouldReceive('publishToken')
            ->once()
            ->with(
                $pendingAssistant->id,
                'جواب',
            )
            ->ordered();

        $this->streamService
            ->shouldReceive('publishCompleted')
            ->once()
            ->with($pendingAssistant->id)
            ->ordered();

        $this->runJob(
            userId: $user->id,
            conversationId: $conversation->id,
            questionId: $question->id,
        );

        $assistant = $conversation->messages()
            ->where('role', MessageRole::Assistant->value)
            ->sole();

        $this->assertSame(
            MessageStatus::Completed,
            $assistant->status,
        );

        $this->assertSame(
            'هذه هي الإجابة النهائية.',
            $assistant->content,
        );

        $this->assertSame(
            $question->id,
            $assistant->execution_snapshot['question_message_id'],
        );

        $this->assertSame(
            [
                [
                    'document_id' => $readyDocument->id,
                    'processing_run_id' => $readyRun->id,
                    'processing_profile' => 'cloud',
                ],
            ],
            $assistant->execution_snapshot['document_targets'],
        );

        $this->assertNull(
            $assistant->metrics['query_embedding'],
        );

        $this->assertNull(
            $assistant->metrics['reranking'],
        );

        $this->assertSame(
            1046.625,
            $assistant->metrics['total'],
        );

        $source = $assistant->sources()->sole();

        $this->assertSame(
            $readyRun->id,
            $source->processing_run_id,
        );

        $this->assertSame(
            'bf9f6557-d595-599b-8704-6a115fd102c3',
            $source->qdrant_point_id,
        );

        $this->assertSame(17, $source->chunk_index);
        $this->assertNull($source->relevance_score);

        $this->assertSame(
            'مقتطف المصدر المستخدم في الإجابة.',
            $source->source_snapshot['excerpt'],
        );

        $this->assertSame(
            0.03125,
            $source->source_snapshot['retrieval_score'],
        );

        $this->assertSame(
            0.81,
            $source->reranker_score,
        );

        $this->assertDatabaseHas('conversation_document', [
            'conversation_id' => $conversation->id,
            'document_id' => $unreadyDocument->id,
        ]);
    }

    public function test_job_sends_mixed_trusted_targets_and_persists_source_provenance(): void
    {
        [$user, $conversation, $question] = $this->conversationWithQuestion();

        [$cloudDocument, $cloudRun] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        [$hybridDocument, $hybridRun] = $this->readyDocument(
            $user,
            ProcessingProfile::HybridLocal,
        );

        $unreadyDocument = $this->unreadyDocument($user);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        [$foreignDocument] = $this->readyDocument(
            $otherUser,
            ProcessingProfile::Cloud,
        );

        $conversation->documents()->attach([
            $cloudDocument->id,
            $hybridDocument->id,
            $unreadyDocument->id,
            $foreignDocument->id,
        ]);

        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('streamRagQuery')
            ->once()
            ->andReturnUsing(
                function (RagQueryRequestData $data) use (
                    $user,
                    $question,
                    $cloudDocument,
                    $cloudRun,
                    $hybridDocument,
                    $hybridRun,
                    $unreadyDocument,
                    $foreignDocument,
                ): Generator {
                    $this->assertSame($user->id, $data->userId);
                    $this->assertSame($question->content, $data->question);

                    $targets = collect($data->documentTargets)
                        ->keyBy('document_id');

                    $this->assertCount(2, $targets);

                    $this->assertSame([
                        'document_id' => $cloudDocument->id,
                        'processing_run_id' => $cloudRun->id,
                        'processing_profile' => ProcessingProfile::Cloud->value,
                    ], $targets->get($cloudDocument->id));

                    $this->assertSame([
                        'document_id' => $hybridDocument->id,
                        'processing_run_id' => $hybridRun->id,
                        'processing_profile' => ProcessingProfile::HybridLocal->value,
                    ], $targets->get($hybridDocument->id));

                    $this->assertFalse($targets->has($unreadyDocument->id));
                    $this->assertFalse($targets->has($foreignDocument->id));

                    $event = $this->completedEvent(
                        $cloudDocument,
                        $cloudRun,
                    );

                    yield new RagCompletedEventData(
                        answer: $event->answer,
                        sources: [
                            ...$event->sources,
                            new RagSourceData(
                                pointId: '7fa1d211-53f4-5bc7-8948-91ce27f01234',
                                retrievalScore: 0.875,
                                rerankerScore: 0.92,
                                documentId: $hybridDocument->id,
                                processingRunId: $hybridRun->id,
                                processingProfile: $hybridRun->profile,
                                chunkIndex: 29,
                                text: 'مقتطف من الوثيقة ذات المعالجة المحلية.',
                                page: 8,
                                section: 'القسم المحلي',
                                source: $hybridDocument->original_name,
                            ),
                        ],
                        timings: $event->timings,
                    );
                },
            );

        $this->app->instance(AiServiceClient::class, $client);

        $this->runJob(
            $user->id,
            $conversation->id,
            $question->id,
        );

        $assistant = $conversation->messages()
            ->where('role', MessageRole::Assistant->value)
            ->sole();

        $this->assertSame(MessageStatus::Completed, $assistant->status);

        $persistedTargets = collect(
            $assistant->execution_snapshot['document_targets'],
        )->keyBy('document_id');

        $this->assertCount(2, $persistedTargets);
        $this->assertTrue($persistedTargets->has($cloudDocument->id));
        $this->assertTrue($persistedTargets->has($hybridDocument->id));
        $this->assertFalse($persistedTargets->has($unreadyDocument->id));
        $this->assertFalse($persistedTargets->has($foreignDocument->id));

        $sources = $assistant->sources()
            ->with('processingRun.document')
            ->get()
            ->keyBy('processing_run_id');

        $this->assertCount(2, $sources);

        $cloudSource = $sources->get($cloudRun->id);
        $hybridSource = $sources->get($hybridRun->id);

        $this->assertSame(
            $cloudDocument->id,
            $cloudSource->processingRun->document->id,
        );
        $this->assertSame(
            ProcessingProfile::Cloud,
            $cloudSource->processingRun->profile,
        );

        $this->assertSame(
            $hybridDocument->id,
            $hybridSource->processingRun->document->id,
        );
        $this->assertSame(
            ProcessingProfile::HybridLocal,
            $hybridSource->processingRun->profile,
        );

        $this->assertSame(29, $hybridSource->chunk_index);
        $this->assertSame(
            'مقتطف من الوثيقة ذات المعالجة المحلية.',
            $hybridSource->source_snapshot['excerpt'],
        );
    }

    public function test_retry_sends_only_last_two_completed_turns_without_duplicate_context(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'M9 context integration',
        ]);

        $this->completedTurn($conversation, 'user one', 'assistant one');
        $this->completedTurn($conversation, 'user two', 'assistant two');

        $pendingQuestion = $conversation->messages()->create([
            'role' => MessageRole::User,
            'status' => MessageStatus::Completed,
            'content' => 'pending history user',
        ]);

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Pending,
            'content' => null,
            'execution_snapshot' => [
                'question_message_id' => $pendingQuestion->id,
            ],
        ]);

        $failedQuestion = $conversation->messages()->create([
            'role' => MessageRole::User,
            'status' => MessageStatus::Completed,
            'content' => 'failed history user',
        ]);

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Failed,
            'content' => 'تعذر إنشاء الإجابة.',
            'execution_snapshot' => [
                'question_message_id' => $failedQuestion->id,
            ],
        ]);

        $this->completedTurn($conversation, 'user three', 'assistant three');

        $foreignUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $foreignConversation = $foreignUser->conversations()->create([
            'title' => 'Foreign conversation',
        ]);

        $this->completedTurn(
            $foreignConversation,
            'foreign user',
            'foreign assistant',
        );

        $currentQuestion = $conversation->messages()->create([
            'role' => MessageRole::User,
            'status' => MessageStatus::Completed,
            'content' => 'current retry question',
        ]);

        $currentAssistant = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Failed,
            'content' => 'تعذر إنشاء الإجابة.',
            'execution_snapshot' => [
                'question_message_id' => $currentQuestion->id,
            ],
        ]);

        [$document, $run] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        $conversation->documents()->attach($document->id);

        $expectedTurns = [
            ['user' => 'user two', 'assistant' => 'assistant two'],
            ['user' => 'user three', 'assistant' => 'assistant three'],
        ];

        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('streamRagQuery')
            ->once()
            ->andReturnUsing(
                function (RagQueryRequestData $data) use (
                    $currentQuestion,
                    $expectedTurns,
                    $document,
                    $run,
                ): Generator {
                    $this->assertSame(
                        $currentQuestion->content,
                        $data->question,
                    );

                    $this->assertSame(
                        $expectedTurns,
                        $data->recentCompletedTurns,
                    );

                    yield $this->completedEvent($document, $run);
                },
            );

        $this->app->instance(AiServiceClient::class, $client);

        $this->streamService
            ->shouldReceive('reset')
            ->once()
            ->with($currentAssistant->id);

        app(ConversationAnswerLifecycleService::class)->retry(
            user: $user,
            conversation: $conversation,
            assistantMessageId: $currentAssistant->id,
        );

        Queue::assertPushed(AskConversationJob::class, 1);

        $this->runJob(
            $user->id,
            $conversation->id,
            $currentQuestion->id,
        );

        $assistant = $currentAssistant->fresh();

        $this->assertSame(MessageStatus::Completed, $assistant->status);

        $this->assertSame(
            $expectedTurns,
            $assistant->execution_snapshot['recent_completed_turns'],
        );

        $this->assertSame(
            1,
            $conversation->messages()
                ->where('role', MessageRole::User->value)
                ->where('content', 'current retry question')
                ->count(),
        );

        $this->assertSame(
            1,
            $conversation->messages()
                ->where('role', MessageRole::Assistant->value)
                ->where(
                    'execution_snapshot->question_message_id',
                    $currentQuestion->id,
                )
                ->count(),
        );
    }

    public function test_job_rejects_source_outside_trusted_execution_targets(): void
    {
        [$user, $conversation, $question] = $this->conversationWithQuestion();

        [$trustedDocument] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        [$foreignDocument, $foreignRun] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        $conversation->documents()->attach($trustedDocument->id);

        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('streamRagQuery')
            ->once()
            ->andReturnUsing(
                function () use (
                    $foreignDocument,
                    $foreignRun,
                ): Generator {
                    yield $this->completedEvent(
                        document: $foreignDocument,
                        run: $foreignRun,
                    );
                },
            );

        $this->app->instance(AiServiceClient::class, $client);

        try {
            $this->runJob(
                userId: $user->id,
                conversationId: $conversation->id,
                questionId: $question->id,
            );

            $this->fail('Untrusted source should have been rejected.');
        } catch (AiServiceException) {
            //
        }

        $this->assertFailedAssistantMessage(
            $conversation->id,
        );

        $this->assertSame(
            0,
            MessageSource::query()->count(),
        );
    }

    public function test_job_does_not_persist_when_stream_has_no_completed_event(): void
    {
        [$user, $conversation, $question] = $this->conversationWithQuestion();

        [$document] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        $conversation->documents()->attach($document->id);

        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('streamRagQuery')
            ->once()
            ->andReturnUsing(
                function (): Generator {
                    yield new RagTokenEventData(
                        content: 'جواب جزئي',
                    );
                },
            );

        $this->app->instance(AiServiceClient::class, $client);

        try {
            $this->runJob(
                userId: $user->id,
                conversationId: $conversation->id,
                questionId: $question->id,
            );

            $this->fail('Missing completed event should fail.');
        } catch (AiServiceException) {
            //
        }

        $this->assertFailedAssistantMessage($conversation->id);
    }

    public function test_job_does_not_persist_partial_answer_when_stream_fails(): void
    {
        [$user, $conversation, $question] = $this->conversationWithQuestion();

        [$document] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        $conversation->documents()->attach($document->id);

        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('streamRagQuery')
            ->once()
            ->andReturnUsing(
                function (): Generator {
                    yield new RagTokenEventData(
                        content: 'جواب جزئي',
                    );

                    throw new AiServiceException(
                        message: 'Stream interrupted.',
                    );
                },
            );

        $this->app->instance(AiServiceClient::class, $client);

        try {
            $this->runJob(
                userId: $user->id,
                conversationId: $conversation->id,
                questionId: $question->id,
            );

            $this->fail('Interrupted stream should fail.');
        } catch (AiServiceException) {
            //
        }

        $this->assertFailedAssistantMessage($conversation->id);
    }

    public function test_job_rejects_repeated_completed_event_without_persisting(): void
    {
        [$user, $conversation, $question] = $this->conversationWithQuestion();

        [$document, $run] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        $conversation->documents()->attach($document->id);

        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('streamRagQuery')
            ->once()
            ->andReturnUsing(
                function () use ($document, $run): Generator {
                    yield $this->completedEvent($document, $run);
                    yield $this->completedEvent($document, $run);
                },
            );

        $this->app->instance(AiServiceClient::class, $client);

        try {
            $this->runJob(
                userId: $user->id,
                conversationId: $conversation->id,
                questionId: $question->id,
            );

            $this->fail('Repeated completed event should fail.');
        } catch (AiServiceException) {
            //
        }

        $this->assertFailedAssistantMessage($conversation->id);
    }

    public function test_repeated_job_execution_does_not_duplicate_answer_or_sources(): void
    {
        [$user, $conversation, $question] = $this->conversationWithQuestion();

        [$document, $run] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        $conversation->documents()->attach($document->id);

        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('streamRagQuery')
            ->once()
            ->andReturnUsing(
                function () use ($document, $run): Generator {
                    yield $this->completedEvent($document, $run);
                },
            );

        $this->app->instance(AiServiceClient::class, $client);

        $this->runJob(
            userId: $user->id,
            conversationId: $conversation->id,
            questionId: $question->id,
        );

        $this->runJob(
            userId: $user->id,
            conversationId: $conversation->id,
            questionId: $question->id,
        );

        $this->assertSame(
            1,
            $conversation->messages()
                ->where('role', MessageRole::Assistant->value)
                ->count(),
        );

        $this->assertSame(1, MessageSource::query()->count());
    }

    public function test_transaction_failure_rolls_back_answer_and_sources(): void
    {
        [$user, $conversation, $question] = $this->conversationWithQuestion();

        [$document, $run] = $this->readyDocument(
            $user,
            ProcessingProfile::Cloud,
        );

        $conversation->documents()->attach($document->id);

        $client = Mockery::mock(AiServiceClient::class);

        $client
            ->shouldReceive('streamRagQuery')
            ->once()
            ->andReturnUsing(
                function () use ($document, $run): Generator {
                    yield $this->completedEvent($document, $run);
                },
            );

        $this->app->instance(AiServiceClient::class, $client);

        $eventName = 'eloquent.creating: '.MessageSource::class;

        Event::listen(
            $eventName,
            function (): void {
                throw new RuntimeException(
                    'Forced source persistence failure.',
                );
            },
        );

        try {
            $this->runJob(
                userId: $user->id,
                conversationId: $conversation->id,
                questionId: $question->id,
            );

            $this->fail('Transaction failure was expected.');
        } catch (RuntimeException) {
            //
        } finally {
            Event::forget($eventName);
        }

        $this->assertFailedAssistantMessage($conversation->id);
        $this->assertSame(0, MessageSource::query()->count());
    }

    private function runJob(
        int $userId,
        int $conversationId,
        int $questionId,
    ): void {
        $assistant = Message::query()
            ->where(
                'conversation_id',
                $conversationId,
            )
            ->where(
                'role',
                MessageRole::Assistant->value,
            )
            ->where(
                'execution_snapshot->question_message_id',
                $questionId,
            )
            ->sole();

        $job = new AskConversationJob(
            userId: $userId,
            conversationId: $conversationId,
            userMessageId: $questionId,
            assistantMessageId: $assistant->id,
        );

        $job->handle(
            app(AskConversationService::class),
        );
    }

    private function completedTurn(
        Conversation $conversation,
        string $userContent,
        string $assistantContent,
    ): void {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'status' => MessageStatus::Completed,
            'content' => $userContent,
        ]);

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => $assistantContent,
        ]);
    }

    /**
     * @return array{User, Conversation, Message}
     */
    private function conversationWithQuestion(): array
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'M5 test',
        ]);

        $question = $conversation->messages()->create([
            'role' => MessageRole::User,
            'status' => MessageStatus::Completed,
            'content' => 'ما محتوى الوثيقة؟',
        ]);

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Pending,
            'content' => null,
            'execution_snapshot' => [
                'question_message_id' => $question->id,
            ],
        ]);

        return [$user, $conversation, $question];
    }

    /**
     * @return array{Document, ProcessingRun}
     */
    private function readyDocument(
        User $user,
        ProcessingProfile $profile,
    ): array {
        $document = $this->document($user);

        $run = $document->processingRuns()->create([
            'profile' => $profile,
            'status' => ProcessingRunStatus::Indexed,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
            'indexed_at' => now(),
        ]);

        $document->forceFill([
            'status' => DocumentStatus::Ready,
            'active_processing_run_id' => $run->id,
        ])->save();

        return [
            $document->fresh(),
            $run->fresh(),
        ];
    }

    private function unreadyDocument(
        User $user,
    ): Document {
        $document = $this->document($user);

        $document->forceFill([
            'status' => DocumentStatus::Processing,
        ])->save();

        $document->processingRuns()->create([
            'profile' => ProcessingProfile::Cloud,
            'status' => ProcessingRunStatus::Processing,
            'kind' => ProcessingRunKind::Initial,
            'profile_snapshot' => [],
            'stage_timings_ms' => [],
        ]);

        return $document->fresh();
    }

    private function document(
        User $user,
    ): Document {
        $name = Str::uuid().'.pdf';

        return $user->documents()->create([
            'original_name' => $name,
            'stored_name' => Str::uuid().'-'.$name,
            'file_path' => 'documents/'.$name,
            'file_type' => FileType::Pdf,
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sha256' => hash('sha256', $name),
        ]);
    }

    private function completedEvent(
        Document $document,
        ProcessingRun $run,
    ): RagCompletedEventData {
        return new RagCompletedEventData(
            answer: 'هذه هي الإجابة النهائية.',
            sources: [
                new RagSourceData(
                    pointId: 'bf9f6557-d595-599b-8704-6a115fd102c3',
                    retrievalScore: 0.03125,
                    rerankerScore: 0.81,
                    documentId: $document->id,
                    processingRunId: $run->id,
                    processingProfile: $run->profile,
                    chunkIndex: 17,
                    text: 'مقتطف المصدر المستخدم في الإجابة.',
                    page: 5,
                    section: 'القسم الأول',
                    source: $document->original_name,
                ),
            ],
            timings: new RagTimingsData(
                queryEmbedding: null,
                retrieval: 120.25,
                fusion: 15.5,
                reranking: null,
                contextBuilding: 10.75,
                generation: 900.125,
                total: 1046.625,
            ),
        );
    }

    private function assertFailedAssistantMessage(
        int $conversationId,
    ): void {
        $assistant = Message::query()
            ->where(
                'conversation_id',
                $conversationId,
            )
            ->where(
                'role',
                MessageRole::Assistant->value,
            )
            ->sole();

        $this->assertSame(
            MessageStatus::Failed,
            $assistant->status,
        );

        $this->assertSame(
            'تعذر إنشاء الإجابة.',
            $assistant->content,
        );
    }
}
