<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversations\Streaming\ConversationAnswerStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class ConversationAnswerStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_receives_progressive_tokens_and_terminal_event(): void
    {
        $user = $this->verifiedUser();

        [$conversation, $assistant] =
            $this->pendingAnswer($user);

        $stream = Mockery::mock(
            ConversationAnswerStreamService::class,
        );

        $stream
            ->shouldReceive('readAfter')
            ->once()
            ->with(
                $assistant->id,
                '0-0',
                10000,
            )
            ->andReturn([
                [
                    'id' => '1000-0',
                    'type' => 'token',
                    'content' => 'الجزء الأول',
                ],
                [
                    'id' => '1001-0',
                    'type' => 'token',
                    'content' => ' ثم الثاني',
                ],
                [
                    'id' => '1002-0',
                    'type' => 'completed',
                    'content' => '',
                ],
            ]);

        $this->app->instance(
            ConversationAnswerStreamService::class,
            $stream,
        );

        $response = $this
            ->actingAs($user)
            ->get(
                route(
                    'conversations.answers.stream',
                    [
                        'conversation' => $conversation,
                        'message' => $assistant,
                    ],
                ),
            );

        $response
            ->assertOk()
            ->assertStreamed();

        $content =
            $response->streamedContent();

        $this->assertStringContainsString(
            "event: token\n",
            $content,
        );

        $this->assertStringContainsString(
            '"content":"الجزء الأول"',
            $content,
        );

        $this->assertStringContainsString(
            '"content":" ثم الثاني"',
            $content,
        );

        $this->assertStringContainsString(
            "event: completed\n",
            $content,
        );

        $firstPosition = strpos(
            $content,
            '"content":"الجزء الأول"',
        );

        $secondPosition = strpos(
            $content,
            '"content":" ثم الثاني"',
        );

        $this->assertIsInt(
            $firstPosition,
        );

        $this->assertIsInt(
            $secondPosition,
        );

        $this->assertLessThan(
            $secondPosition,
            $firstPosition,
        );
    }

    public function test_other_user_cannot_open_answer_stream(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();

        [$conversation, $assistant] =
            $this->pendingAnswer($owner);

        $this
            ->actingAs($other)
            ->get(
                route(
                    'conversations.answers.stream',
                    [
                        'conversation' => $conversation,
                        'message' => $assistant,
                    ],
                ),
            )
            ->assertForbidden();
    }

    public function test_answer_from_another_conversation_cannot_be_streamed(): void
    {
        $user = $this->verifiedUser();

        [$firstConversation] =
            $this->pendingAnswer($user);

        [, $otherAssistant] =
            $this->pendingAnswer($user);

        $this
            ->actingAs($user)
            ->get(
                route(
                    'conversations.answers.stream',
                    [
                        'conversation' => $firstConversation,
                        'message' => $otherAssistant,
                    ],
                ),
            )
            ->assertNotFound();
    }

    /**
     * @return array{Conversation, Message}
     */
    private function pendingAnswer(
        User $user,
    ): array {
        $conversation =
            $user->conversations()->create([
                'title' => 'M8 stream',
            ]);

        $question =
            $conversation->messages()->create([
                'role' => MessageRole::User,
                'status' => MessageStatus::Completed,
                'content' => 'ما الجواب؟',
            ]);

        $assistant =
            $conversation->messages()->create([
                'role' => MessageRole::Assistant,
                'status' => MessageStatus::Pending,
                'execution_snapshot' => [
                    'question_message_id' => $question->id,
                ],
            ]);

        return [
            $conversation,
            $assistant,
        ];
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }
}
