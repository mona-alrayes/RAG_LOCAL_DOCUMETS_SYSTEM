<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationMessageIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_identifies_assistant_and_user_messages_with_the_expected_avatars(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->avatar()->create([
            'disk' => 'public',
            'path' => 'avatars/mona.png',
        ]);
        Storage::disk('public')->put('avatars/mona.png', 'image');

        $conversation = $user->conversations()->create(['title' => 'هوية الرسائل']);
        $conversation->messages()->createMany([
            [
                'role' => MessageRole::User,
                'status' => MessageStatus::Completed,
                'content' => 'سؤال المستخدم',
            ],
            [
                'role' => MessageRole::Assistant,
                'status' => MessageStatus::Completed,
                'content' => 'إجابة المساعد',
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk();

        $response
            ->assertSee('data-message-avatar="user"', false)
            ->assertSee('/storage/avatars/mona.png', false)
            ->assertSee('data-message-avatar="assistant"', false)
            ->assertSee('aria-label="مساعد الذكاء الاصطناعي"', false);
    }

    public function test_users_without_a_photo_receive_the_default_avatar_everywhere(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $conversation = $user->conversations()->create(['title' => 'صورة افتراضية']);
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'status' => MessageStatus::Completed,
            'content' => 'رسالة',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk();

        $response
            ->assertSee('/images/default-avatar.svg', false)
            ->assertSee('data-sidebar-user-avatar', false);
    }
}
