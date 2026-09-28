<?php

namespace Tests\Feature\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ConversationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_any_and_create_conversations(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(
            Gate::forUser($user)->allows('viewAny', Conversation::class)
        );

        $this->assertTrue(
            Gate::forUser($user)->allows('create', Conversation::class)
        );
    }

    public function test_owner_is_allowed_to_manage_their_conversation(): void
    {
        $owner = User::factory()->create();

        $conversation = $owner->conversations()->create([
            'title' => 'Owner conversation',
        ]);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(
                Gate::forUser($owner)->allows($ability, $conversation)
            );
        }
    }

    public function test_other_user_is_denied_from_managing_the_conversation(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $conversation = $owner->conversations()->create([
            'title' => 'Private conversation',
        ]);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertFalse(
                Gate::forUser($otherUser)->allows($ability, $conversation)
            );
        }
    }
}
