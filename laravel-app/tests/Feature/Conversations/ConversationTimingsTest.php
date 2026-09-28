<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConversationTimingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_assistant_message_displays_saved_timings_without_fake_null_values(): void
    {
        $user = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة التوقيتات',
        ]);

        $message = $this->createAssistantMessage(
            conversation: $conversation,
            content: 'إجابة مع توقيتات.',
            metrics: [
                'query_embedding' => null,
                'retrieval' => 210,
                'fusion' => 45,
                'reranking' => 320,
                'context_building' => 80,
                'generation' => 1500,
                'total' => 2400,
            ],
        );

        $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('إجابة مع توقيتات.')
            ->assertSee(
                'data-message-timings="'.$message->id.'"',
                false,
            )
            ->assertSee('data-message-timings-trigger', false)
            ->assertSee('<details', false)
            ->assertSee('<summary', false)
            ->assertSee('إجمالي الوقت')
            ->assertSee('2.4 s')
            ->assertSee('توليد الإجابة')
            ->assertSee('1.5 s')
            ->assertSee('إعادة الترتيب')
            ->assertSee('320 ms')
            ->assertSee('الاسترجاع')
            ->assertSee('210 ms')
            ->assertSee('بناء السياق')
            ->assertSee('80 ms')
            ->assertSee('دمج النتائج')
            ->assertSee('45 ms')
            ->assertDontSee(
                'data-message-timing="query_embedding"',
                false,
            )
            ->assertDontSee('تمثيل السؤال');
    }

    public function test_assistant_message_without_metrics_renders_without_timings_ui(): void
    {
        $user = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة بدون توقيتات',
        ]);

        $this->createAssistantMessage(
            conversation: $conversation,
            content: 'إجابة بدون metrics.',
            metrics: null,
        );

        $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('إجابة بدون metrics.')
            ->assertDontSee('data-message-timings-trigger', false);
    }

    public function test_each_assistant_message_keeps_its_own_timings(): void
    {
        $user = $this->verifiedUser();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة متعددة الإجابات',
        ]);

        $first = $this->createAssistantMessage(
            conversation: $conversation,
            content: 'الإجابة الأولى',
            metrics: $this->metrics(total: 1100),
        );

        $second = $this->createAssistantMessage(
            conversation: $conversation,
            content: 'الإجابة الثانية',
            metrics: $this->metrics(total: 2200),
        );

        $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee(
                'data-message-timings="'.$first->id.'"',
                false,
            )
            ->assertSee(
                'data-message-timings="'.$second->id.'"',
                false,
            )
            ->assertSee('1.1 s')
            ->assertSee('2.2 s');
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, int|null>|null  $metrics
     */
    private function createAssistantMessage(
        Conversation $conversation,
        string $content,
        ?array $metrics,
    ): Message {
        return $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => $content,
            'metrics' => $metrics,
        ]);
    }

    /**
     * @return array<string, int|null>
     */
    private function metrics(int $total): array
    {
        return [
            'query_embedding' => null,
            'retrieval' => 120,
            'fusion' => 15,
            'reranking' => null,
            'context_building' => 10,
            'generation' => 900,
            'total' => $total,
        ];
    }
}
