<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Conversations\RecentCompletedTurnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentCompletedTurnServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_no_turns_when_history_is_empty(): void
    {
        $conversation = $this->conversation();

        $turns = $this->service()->forConversation($conversation);

        $this->assertSame([], $turns->all());
    }

    public function test_returns_one_completed_turn(): void
    {
        $conversation = $this->conversation();

        $this->message(
            $conversation,
            MessageRole::User,
            MessageStatus::Completed,
            'user one',
        );

        $this->message(
            $conversation,
            MessageRole::Assistant,
            MessageStatus::Completed,
            'assistant one',
        );

        $turns = $this->service()->forConversation($conversation);

        $this->assertSame([
            [
                'user' => 'user one',
                'assistant' => 'assistant one',
            ],
        ], $turns->all());
    }

    public function test_returns_only_last_two_completed_turns(): void
    {
        $conversation = $this->conversation();

        $this->completedTurn($conversation, 'user one', 'assistant one');
        $this->completedTurn($conversation, 'user two', 'assistant two');
        $this->completedTurn($conversation, 'user three', 'assistant three');

        $turns = $this->service()->forConversation($conversation);

        $this->assertSame([
            [
                'user' => 'user two',
                'assistant' => 'assistant two',
            ],
            [
                'user' => 'user three',
                'assistant' => 'assistant three',
            ],
        ], $turns->all());
    }

    public function test_completed_turns_are_returned_in_chronological_order(): void
    {
        $conversation = $this->conversation();

        $this->completedTurn($conversation, 'older user', 'older assistant');
        $this->completedTurn($conversation, 'newer user', 'newer assistant');

        $turns = $this->service()->forConversation($conversation);

        $this->assertSame([
            [
                'user' => 'older user',
                'assistant' => 'older assistant',
            ],
            [
                'user' => 'newer user',
                'assistant' => 'newer assistant',
            ],
        ], $turns->all());
    }

    public function test_pending_message_does_not_form_completed_turn(): void
    {
        $conversation = $this->conversation();

        $this->message(
            $conversation,
            MessageRole::User,
            MessageStatus::Completed,
            'invalid user',
        );

        $this->message(
            $conversation,
            MessageRole::Assistant,
            MessageStatus::Pending,
            null,
        );

        $this->completedTurn(
            $conversation,
            'valid user',
            'valid assistant',
        );

        $turns = $this->service()->forConversation($conversation);

        $this->assertSame([
            [
                'user' => 'valid user',
                'assistant' => 'valid assistant',
            ],
        ], $turns->all());
    }

    public function test_failed_message_does_not_form_completed_turn(): void
    {
        $conversation = $this->conversation();

        $this->message(
            $conversation,
            MessageRole::User,
            MessageStatus::Completed,
            'invalid user',
        );

        $this->message(
            $conversation,
            MessageRole::Assistant,
            MessageStatus::Failed,
            'failed answer',
        );

        $this->completedTurn(
            $conversation,
            'valid user',
            'valid assistant',
        );

        $turns = $this->service()->forConversation($conversation);

        $this->assertSame([
            [
                'user' => 'valid user',
                'assistant' => 'valid assistant',
            ],
        ], $turns->all());
    }

    public function test_incomplete_pair_is_excluded(): void
    {
        $conversation = $this->conversation();

        $this->completedTurn(
            $conversation,
            'completed user',
            'completed assistant',
        );

        $this->message(
            $conversation,
            MessageRole::User,
            MessageStatus::Completed,
            'unanswered user',
        );

        $turns = $this->service()->forConversation($conversation);

        $this->assertSame([
            [
                'user' => 'completed user',
                'assistant' => 'completed assistant',
            ],
        ], $turns->all());
    }

    public function test_messages_from_other_conversation_are_excluded(): void
    {
        $conversation = $this->conversation();
        $otherConversation = $this->conversation();

        $this->completedTurn(
            $otherConversation,
            'foreign user',
            'foreign assistant',
        );

        $this->completedTurn(
            $conversation,
            'local user',
            'local assistant',
        );

        $turns = $this->service()->forConversation($conversation);

        $this->assertSame([
            [
                'user' => 'local user',
                'assistant' => 'local assistant',
            ],
        ], $turns->all());
    }

    public function test_unpaired_current_user_message_is_not_history(): void
    {
        $conversation = $this->conversation();

        $this->completedTurn($conversation, 'user one', 'assistant one');
        $this->completedTurn($conversation, 'user two', 'assistant two');

        $this->message(
            $conversation,
            MessageRole::User,
            MessageStatus::Completed,
            'current question',
        );

        $turns = $this->service()->forConversation($conversation);

        $this->assertSame([
            [
                'user' => 'user one',
                'assistant' => 'assistant one',
            ],
            [
                'user' => 'user two',
                'assistant' => 'assistant two',
            ],
        ], $turns->all());
    }

    public function test_same_messages_produce_same_deterministic_result(): void
    {
        $conversation = $this->conversation();

        $this->completedTurn($conversation, 'user one', 'assistant one');
        $this->completedTurn($conversation, 'user two', 'assistant two');

        $first = $this->service()
            ->forConversation($conversation)
            ->all();

        $second = $this->service()
            ->forConversation($conversation)
            ->all();

        $this->assertSame($first, $second);
    }

    private function service(): RecentCompletedTurnService
    {
        return app(RecentCompletedTurnService::class);
    }

    private function conversation(): Conversation
    {
        $user = User::factory()->create();

        return $user->conversations()->create([
            'title' => 'Test conversation',
        ]);
    }

    private function completedTurn(
        Conversation $conversation,
        string $userContent,
        string $assistantContent,
    ): void {
        $this->message(
            $conversation,
            MessageRole::User,
            MessageStatus::Completed,
            $userContent,
        );

        $this->message(
            $conversation,
            MessageRole::Assistant,
            MessageStatus::Completed,
            $assistantContent,
        );
    }

    private function message(
        Conversation $conversation,
        MessageRole $role,
        MessageStatus $status,
        ?string $content,
    ): void {
        $conversation->messages()->create([
            'role' => $role,
            'status' => $status,
            'content' => $content,
        ]);
    }
}
