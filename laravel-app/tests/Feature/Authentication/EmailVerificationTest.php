<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_verify_email_using_a_valid_signed_url(): void
    {
        Event::fake();

        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );

        $response = $this
            ->actingAs($user)
            ->get($verificationUrl);

        $response->assertRedirect(route('workspace').'?verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        Event::assertDispatched(Verified::class);
    }

    public function test_unsigned_verification_url_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = route('verification.verify', [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)
            ->get($verificationUrl)
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unverified_user_can_request_another_verification_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('verification.notice'))
            ->post(route('verification.send'));

        $response
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verified_user_does_not_receive_another_verification_link(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('verification.send'));

        $response->assertRedirect(route('workspace'));

        Notification::assertNothingSent();
    }
}
