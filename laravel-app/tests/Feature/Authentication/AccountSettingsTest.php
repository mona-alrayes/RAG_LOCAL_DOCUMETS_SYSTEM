<?php

namespace Tests\Feature\Authentication;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_name_without_losing_email_verification(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user-profile-information.update'), [
                'name' => 'الاسم الجديد',
                'email' => $user->email,
            ]);

        $response
            ->assertRedirect(route('settings.account'))
            ->assertSessionHas('status', 'profile-information-updated');

        $user->refresh();

        $this->assertSame('الاسم الجديد', $user->name);
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_changing_email_requires_verification_again(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('user-profile-information.update'), [
                'name' => $user->name,
                'email' => 'new-email@example.com',
            ])
            ->assertSessionHas('status', 'profile-information-updated');

        $user->refresh();

        $this->assertSame('new-email@example.com', $user->email);
        $this->assertFalse($user->hasVerifiedEmail());

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_email_must_be_unique_when_updating_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user-profile-information.update'), [
                'name' => $user->name,
                'email' => $otherUser->email,
            ]);

        $response
            ->assertRedirect(route('settings.account'))
            ->assertSessionHasErrorsIn(
                'updateProfileInformation',
                ['email'],
            );

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_user_can_upload_a_profile_photo_and_replace_the_previous_one(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $user->avatar()->create([
            'disk' => 'public',
            'path' => 'avatars/old-avatar.png',
        ]);
        Storage::disk('public')->put('avatars/old-avatar.png', 'old image');

        $response = $this
            ->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user-profile-information.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('profile.webp', 320, 320),
            ]);

        $response
            ->assertRedirect(route('settings.account'))
            ->assertSessionHas('status', 'profile-information-updated');

        $avatar = $user->fresh()->avatar;

        $this->assertNotNull($avatar);
        $this->assertSame('public', $avatar->disk);
        $this->assertStringStartsWith('avatars/', $avatar->path);
        $this->assertDatabaseCount('images', 1);
        Storage::disk('public')->assertExists($avatar->path);
        Storage::disk('public')->assertMissing('avatars/old-avatar.png');
    }

    public function test_profile_photo_must_be_a_supported_image_no_larger_than_two_megabytes(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user-profile-information.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->create('avatar.svg', 10, 'image/svg+xml'),
            ])
            ->assertRedirect(route('settings.account'))
            ->assertSessionHasErrorsIn('updateProfileInformation', ['avatar']);

        $this
            ->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user-profile-information.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('large.jpg')->size(2049),
            ])
            ->assertRedirect(route('settings.account'))
            ->assertSessionHasErrorsIn('updateProfileInformation', ['avatar']);

        $this->assertNull($user->fresh()->avatar);
        $this->assertDatabaseCount('images', 0);
    }

    public function test_user_can_update_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user-password.update'), [
                'current_password' => 'OldPassword123!',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

        $response
            ->assertRedirect(route('settings.account'))
            ->assertSessionHas('status', 'password-updated');

        $this->assertTrue(
            Hash::check('NewPassword123!', $user->fresh()->password)
        );
    }

    public function test_password_is_not_changed_when_current_password_is_wrong(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword123!'),
        ]);

        $response = $this
            ->actingAs($user)
            ->from(route('settings.account'))
            ->put(route('user-password.update'), [
                'current_password' => 'WrongPassword123!',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ]);

        $response
            ->assertRedirect(route('settings.account'))
            ->assertSessionHasErrorsIn(
                'updatePassword',
                ['current_password'],
            );

        $this->assertTrue(
            Hash::check('OldPassword123!', $user->fresh()->password)
        );
    }
}
