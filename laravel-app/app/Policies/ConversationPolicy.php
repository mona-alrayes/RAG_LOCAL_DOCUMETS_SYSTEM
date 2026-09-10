<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Determine whether the user can view any conversations.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the conversation.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }

    /**
     * Determine whether the user can create conversations.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the conversation.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }

    /**
     * Determine whether the user can delete the conversation.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->user_id;
    }
}
