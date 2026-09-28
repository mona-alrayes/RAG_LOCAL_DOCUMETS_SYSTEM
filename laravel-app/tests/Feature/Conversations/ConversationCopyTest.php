<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_completed_messages_have_copy_controls_and_source_is_escaped(): void
    {
        // نختبر صفحة Laravel الحقيقية، لا قالباً مقلداً لزر النسخ.
        $user = User::factory()->create(['email_verified_at' => now()]);
        $conversation = $user->conversations()->create(['title' => 'تجربة النسخ']);
        foreach ([MessageStatus::Completed, MessageStatus::Pending, MessageStatus::Failed] as $status) {
            $conversation->messages()->create([
                'role' => MessageRole::Assistant,
                'status' => $status,
                'content' => '**جواب** <script>alert("x")</script>',
            ]);
        }
        $response = $this->actingAs($user)->get(route('conversations.show', $conversation))->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'data-copy-message'));
        $this->assertStringContainsString('data-message-copy-source="**جواب** &lt;script&gt;', $html);
        $this->assertStringContainsString('aria-label="نسخ الرسالة"', $html);
        $this->assertStringContainsString('data-copy-icon', $html);
        $this->assertStringContainsString('data-copy-success-icon', $html);
        $this->assertStringNotContainsString('>نسخ الرسالة</button>', $html);
        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
    }
}
