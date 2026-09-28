<?php

namespace Tests\Feature\Conversations;

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_rename_conversation_and_title_is_trimmed(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'الاسم القديم',
        ]);

        $this
            ->actingAs($user)
            ->patch(
                route('conversations.update', $conversation),
                [
                    'title' => '  الاسم الجديد  ',
                ],
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'user_id' => $user->id,
            'title' => 'الاسم الجديد',
        ]);
    }

    public function test_rename_requires_non_empty_title_with_maximum_255_characters(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'الاسم الأصلي',
        ]);

        $this
            ->actingAs($user)
            ->from(route('conversations.show', $conversation))
            ->patch(
                route('conversations.update', $conversation),
                [
                    'title' => '   ',
                ],
            )
            ->assertRedirect(
                route('conversations.show', $conversation),
            )
            ->assertSessionHasErrors('title');

        $this
            ->actingAs($user)
            ->from(route('conversations.show', $conversation))
            ->patch(
                route('conversations.update', $conversation),
                [
                    'title' => str_repeat('a', 256),
                ],
            )
            ->assertRedirect(
                route('conversations.show', $conversation),
            )
            ->assertSessionHasErrors('title');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'title' => 'الاسم الأصلي',
        ]);
    }

    public function test_rename_rejects_non_string_title_without_mutating_conversation(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'الاسم الأصلي',
        ]);

        $this
            ->actingAs($user)
            ->from(route('conversations.show', $conversation))
            ->patch(
                route('conversations.update', $conversation),
                [
                    'title' => ['invalid'],
                ],
            )
            ->assertRedirect(
                route('conversations.show', $conversation),
            )
            ->assertSessionHasErrors('title');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'user_id' => $user->id,
            'title' => 'الاسم الأصلي',
        ]);
    }

    public function test_other_user_cannot_rename_conversation(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $owner->conversations()->create([
            'title' => 'محادثة المالك',
        ]);

        $this
            ->actingAs($otherUser)
            ->patch(
                route('conversations.update', $conversation),
                [
                    'title' => 'اسم غير مصرح',
                ],
            )
            ->assertForbidden();

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'user_id' => $owner->id,
            'title' => 'محادثة المالك',
        ]);
    }

    public function test_owner_can_delete_conversation_without_pending_answer(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'محادثة للحذف',
        ]);

        $message = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Completed,
            'content' => 'إجابة مكتملة.',
        ]);

        $this
            ->actingAs($user)
            ->delete(
                route('conversations.destroy', $conversation),
            )
            ->assertRedirect(route('conversations.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('conversations', [
            'id' => $conversation->id,
        ]);

        $this->assertDatabaseMissing('messages', [
            'id' => $message->id,
        ]);
    }

    public function test_conversation_cannot_be_deleted_while_assistant_answer_is_pending(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $user->conversations()->create([
            'title' => 'محادثة قيد الإجابة',
        ]);

        $assistant = $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'status' => MessageStatus::Pending,
            'content' => null,
        ]);

        $this
            ->actingAs($user)
            ->from(route('conversations.show', $conversation))
            ->delete(
                route('conversations.destroy', $conversation),
            )
            ->assertRedirect(
                route('conversations.show', $conversation),
            )
            ->assertSessionHasErrors('conversation_delete');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
        ]);

        $this->assertDatabaseHas('messages', [
            'id' => $assistant->id,
            'conversation_id' => $conversation->id,
            'status' => MessageStatus::Pending->value,
        ]);
    }

    public function test_other_user_cannot_delete_conversation(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $conversation = $owner->conversations()->create([
            'title' => 'محادثة محمية',
        ]);

        $this
            ->actingAs($otherUser)
            ->delete(
                route('conversations.destroy', $conversation),
            )
            ->assertForbidden();

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'user_id' => $owner->id,
        ]);
    }
}
