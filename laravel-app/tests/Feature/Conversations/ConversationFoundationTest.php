<?php

namespace Tests\Feature\Conversations;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConversationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversations_table_has_expected_schema(): void
    {
        $this->assertTrue(Schema::hasTable('conversations'));

        $this->assertTrue(Schema::hasColumns('conversations', [
            'id',
            'user_id',
            'title',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_conversation_belongs_to_user_and_user_has_conversations(): void
    {
        $user = User::factory()->create();

        $conversation = $user->conversations()->create([
            'title' => 'محادثة تجريبية',
        ]);

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertTrue($conversation->user->is($user));
        $this->assertTrue($user->conversations->contains($conversation));
        $this->assertSame('محادثة تجريبية', $conversation->title);
    }
}
