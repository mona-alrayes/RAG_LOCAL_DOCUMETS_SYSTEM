<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_workspace_to_login(): void
    {
        $response = $this->get(route('workspace'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_account_settings_to_login(): void
    {
        $response = $this->get(route('settings.account'));

        $response->assertRedirect(route('login'));
    }

    public function test_unverified_user_is_redirected_to_email_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('workspace'))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($user)
            ->get(route('settings.account'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_user_can_access_workspace_and_account_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('workspace'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('settings.account'))
            ->assertOk();
    }
}
