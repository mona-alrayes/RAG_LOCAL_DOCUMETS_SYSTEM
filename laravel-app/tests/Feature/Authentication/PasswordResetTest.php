<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_a_password_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->post(route('password.email'), [
            'email' => $user->email,
        ]);

        $response->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_user_can_reset_password_using_a_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
        ]);

        $this->post(route('password.email'), [
            'email' => $user->email,
        ]);

        $token = null;

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            }
        );

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertTrue(
            Hash::check('NewPassword123!', $user->fresh()->password)
        );
    }

    public function test_password_is_not_changed_when_reset_token_is_invalid(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
        ]);

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertSessionHasErrors('email');

        $this->assertTrue(
            Hash::check('OldPassword123!', $user->fresh()->password)
        );
    }
}
