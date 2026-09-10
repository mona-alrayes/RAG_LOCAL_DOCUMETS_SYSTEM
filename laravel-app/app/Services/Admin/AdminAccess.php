<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AdminAccess
{
    public static function authorize(?User $actor): void
    {
        if (! $actor || ! $actor->is_admin || $actor->suspended_at !== null || ! $actor->hasVerifiedEmail()) {
            throw new AuthorizationException('Administrator access required.');
        }
    }
}
