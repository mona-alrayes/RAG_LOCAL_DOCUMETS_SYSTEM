<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_only_sees_their_own_conversations(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $user->conversations()->create([
            'title' => 'محادثتي الخاصة',
        ]);

        $otherUser->conversations()->create([
            'title' => 'محادثة مستخدم آخر',
        ]);

        $this
            ->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee('محادثتي الخاصة')
            ->assertDontSee('محادثة مستخدم آخر');
    }

    public function test_empty_conversation_list_renders_chat_layout(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSee('محادثة جديدة')
            ->assertSee('لا توجد محادثات بعد')
            ->assertSee('ابدأ محادثة جديدة');
    }

    public function test_owner_can_open_conversation_inside_chat_layout(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $user->conversations()->create([
            'title' => 'المحادثة الأخرى',
        ]);

        $activeConversation = $user->conversations()->create([
            'title' => 'المحادثة المختارة',
        ]);

        $otherUser->conversations()->create([
            'title' => 'محادثة مستخدم آخر',
        ]);

        $this
            ->actingAs($user)
            ->get(route('conversations.show', $activeConversation))
            ->assertOk()
            ->assertSee('المحادثة المختارة')
            ->assertSee('المحادثة الأخرى')
            ->assertDontSee('محادثة مستخدم آخر')
            ->assertSee('aria-current="page"', false)
            ->assertSeeInOrder([
                'المحادثة المختارة',
                'المحادثة الحالية',
                'المحادثة الأخرى',
            ]);
    }

    public function test_conversation_show_renders_top_document_selector(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'محادثة الوثائق',
        ]);

        $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('data-chat-document-selector', false)
            ->assertSee('اختيار الوثائق');
    }

    public function test_conversation_layout_exposes_responsive_reading_and_navigation_surfaces(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'محادثة متجاوبة',
        ]);

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => 'نص إجابة قابل للقراءة',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation));

        $response
            ->assertOk()
            ->assertSee('data-conversation-column', false)
            ->assertSee('data-conversation-text', false)
            ->assertSee('data-ai-avatar="robot"', false)
            ->assertSee('conversation-avatar', false)
            ->assertSee('conversation-composer-shell flex items-center', false)
            ->assertSee('authenticated-sidebar-subitem', false);
    }

    public function test_conversation_page_contains_scrolling_inside_the_viewport(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'محادثة بدون فراغ سفلي',
        ]);

        $this
            ->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('authenticated-shell--full-bleed', false)
            ->assertSee('authenticated-main--full-bleed', false)
            ->assertSee('data-conversation-viewport', false);
    }

    public function test_authenticated_user_can_create_a_conversation_owned_by_them(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('conversations.store'), [
                'title' => 'محادثة جديدة',
                'user_id' => $otherUser->id,
            ]);

        $conversation = $user->conversations()
            ->where('title', 'محادثة جديدة')
            ->firstOrFail();

        $response
            ->assertRedirect(route('conversations.show', $conversation))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('conversations', [
            'user_id' => $user->id,
            'title' => 'محادثة جديدة',
        ]);

        $this->assertDatabaseMissing('conversations', [
            'user_id' => $otherUser->id,
            'title' => 'محادثة جديدة',
        ]);
    }

    public function test_conversation_title_is_optional(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('conversations.store'), []);

        $conversation = $user->conversations()->firstOrFail();

        $response->assertRedirect(
            route('conversations.show', $conversation),
        );

        $this->assertDatabaseHas('conversations', [
            'user_id' => $user->id,
            'title' => null,
        ]);
    }

    public function test_conversation_title_must_not_exceed_255_characters(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $this
            ->actingAs($user)
            ->post(route('conversations.store'), [
                'title' => str_repeat('a', 256),
            ])
            ->assertSessionHasErrors('title');

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_guest_cannot_access_or_create_conversations(): void
    {
        $this
            ->get(route('conversations.index'))
            ->assertRedirect(route('login'));

        $this
            ->post(route('conversations.store'), [
                'title' => 'Guest conversation',
            ])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('conversations', 0);
    }
}
