<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Jobs\AskConversationJob;
use App\Models\User;
use App\Services\Conversations\ConversationAnswerLifecycleService;
use App\Services\Conversations\Streaming\ConversationAnswerStreamService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Mockery;
use Tests\TestCase;

final class ConversationAnswerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $stream = Mockery::mock(
            ConversationAnswerStreamService::class,
        );

        $stream
            ->shouldReceive('reset')
            ->byDefault();

        $this->app->instance(
            ConversationAnswerStreamService::class,
            $stream,
        );
    }

    public function test_start_creates_question_pending_answer_and_one_job(): void
    {
        Queue::fake();

        $user = $this->verifiedUser();

        $conversation =
            $user->conversations()->create([
                'title' => 'M8 lifecycle',
            ]);

        $assistant = app(
            ConversationAnswerLifecycleService::class,
        )->start(
            user: $user,
            conversation: $conversation,
            question: 'ما أهم نتائج الوثيقة؟',
        );

        $messages = $conversation
            ->messages()
            ->oldest('id')
            ->get();

        $this->assertCount(
            2,
            $messages,
        );

        $question = $messages[0];

        $this->assertSame(
            MessageRole::User,
            $question->role,
        );

        $this->assertSame(
            MessageStatus::Completed,
            $question->status,
        );

        $this->assertSame(
            'ما أهم نتائج الوثيقة؟',
            $question->content,
        );

        $this->assertSame(
            MessageRole::Assistant,
            $assistant->role,
        );

        $this->assertSame(
            MessageStatus::Pending,
            $assistant->status,
        );

        $this->assertNull(
            $assistant->content,
        );

        $this->assertSame(
            $question->id,
            $assistant
                ->execution_snapshot[
                    'question_message_id'
                ],
        );

        Queue::assertPushed(
            AskConversationJob::class,
            function (
                AskConversationJob $job,
            ) use (
                $user,
                $conversation,
                $question,
                $assistant,
            ): bool {
                return $job->userId
                        === $user->id
                    && $job->conversationId
                        === $conversation->id
                    && $job->userMessageId
                        === $question->id
                    && $job->assistantMessageId
                        === $assistant->id;
            },
        );

        Queue::assertPushed(
            AskConversationJob::class,
            1,
        );
    }

    public function test_retry_reuses_failed_answer_and_does_not_dispatch_twice(): void
    {
        Queue::fake();

        $user = $this->verifiedUser();

        $conversation =
            $user->conversations()->create([
                'title' => 'M8 retry',
            ]);

        $question =
            $conversation->messages()->create([
                'role' => MessageRole::User,
                'status' => MessageStatus::Completed,
                'content' => 'أعد المحاولة.',
            ]);

        $assistant =
            $conversation->messages()->create([
                'role' => MessageRole::Assistant,
                'status' => MessageStatus::Failed,
                'content' => 'تعذر إنشاء الإجابة.',
                'execution_snapshot' => [
                    'question_message_id' => $question->id,
                ],
            ]);

        $service = app(
            ConversationAnswerLifecycleService::class,
        );

        $retried = $service->retry(
            user: $user,
            conversation: $conversation,
            assistantMessageId: $assistant->id,
        );

        $this->assertSame(
            $assistant->id,
            $retried->id,
        );

        $this->assertSame(
            MessageStatus::Pending,
            $retried->status,
        );

        $this->assertNull(
            $retried->content,
        );

        Queue::assertPushed(
            AskConversationJob::class,
            1,
        );

        try {
            $service->retry(
                user: $user,
                conversation: $conversation,
                assistantMessageId: $assistant->id,
            );

            $this->fail(
                'Pending answer must not dispatch another retry job.',
            );
        } catch (LogicException) {
            //
        }

        Queue::assertPushed(
            AskConversationJob::class,
            1,
        );
    }

    public function test_retry_rejects_foreign_conversation(): void
    {
        Queue::fake();

        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();

        $conversation =
            $owner->conversations()->create([
                'title' => 'Private conversation',
            ]);

        $question =
            $conversation->messages()->create([
                'role' => MessageRole::User,
                'status' => MessageStatus::Completed,
                'content' => 'سؤال خاص.',
            ]);

        $assistant =
            $conversation->messages()->create([
                'role' => MessageRole::Assistant,
                'status' => MessageStatus::Failed,
                'content' => 'تعذر إنشاء الإجابة.',
                'execution_snapshot' => [
                    'question_message_id' => $question->id,
                ],
            ]);

        $this->expectException(
            AuthorizationException::class,
        );

        app(
            ConversationAnswerLifecycleService::class,
        )->retry(
            user: $other,
            conversation: $conversation,
            assistantMessageId: $assistant->id,
        );
    }

    public function test_pending_and_failed_states_render_without_completed_metadata(): void
    {
        $user = $this->verifiedUser();

        $conversation =
            $user->conversations()->create([
                'title' => 'M8 states',
            ]);

        $question =
            $conversation->messages()->create([
                'role' => MessageRole::User,
                'status' => MessageStatus::Completed,
                'content' => 'السؤال الأول',
            ]);

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Pending,
            'execution_snapshot' => [
                'question_message_id' => $question->id,
            ],
        ]);

        $failedQuestion =
            $conversation->messages()->create([
                'role' => MessageRole::User,
                'status' => MessageStatus::Completed,
                'content' => 'السؤال الثاني',
            ]);

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Failed,
            'content' => 'تعذر إنشاء الإجابة.',
            'execution_snapshot' => [
                'question_message_id' => $failedQuestion->id,
            ],
        ]);

        $this
            ->actingAs($user)
            ->get(
                route(
                    'conversations.show',
                    $conversation,
                ),
            )
            ->assertOk()
            ->assertSee(
                'جاري إعداد الإجابة...',
            )
            ->assertSee(
                'تعذر إنشاء الإجابة.',
            )
            ->assertSee(
                'إعادة المحاولة',
            )
            ->assertSee('for="conversation-question"', false)
            ->assertSee('id="conversation-question"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('aria-busy="true"', false)
            ->assertSee('role="alert"', false)
            ->assertDontSee(
                'data-message-timings-trigger',
                false,
            )
            ->assertDontSee(
                'data-message-source-trigger',
                false,
            );
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }
}
