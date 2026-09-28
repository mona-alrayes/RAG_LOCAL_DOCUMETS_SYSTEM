<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, string|UploadedFile>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'avatar' => [
                'nullable',
                File::image()
                    ->types(['jpg', 'jpeg', 'png', 'webp'])
                    ->max(2 * 1024),
            ],
        ])->validateWithBag('updateProfileInformation');

        $emailChanged = $input['email'] !== $user->email;
        $oldAvatar = $user->avatar()->first();
        $newAvatarPath = null;

        if (($input['avatar'] ?? null) instanceof UploadedFile) {
            $newAvatarPath = $input['avatar']->store('avatars', 'public');
        }

        $values = [
            'name' => $input['name'],
            'email' => $input['email'],
        ];

        if ($newAvatarPath !== null) {
            $user->unsetRelation('avatar');
        }

        try {
            DB::transaction(function () use ($emailChanged, $newAvatarPath, $user, $values): void {
                if ($emailChanged && $user instanceof MustVerifyEmail) {
                    $values['email_verified_at'] = null;
                }

                $user->forceFill($values)->save();

                if ($newAvatarPath !== null) {
                    $user->avatar()->updateOrCreate([], [
                        'disk' => 'public',
                        'path' => $newAvatarPath,
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            if ($newAvatarPath !== null) {
                Storage::disk('public')->delete($newAvatarPath);
            }

            throw $exception;
        }

        if ($newAvatarPath !== null && $oldAvatar !== null) {
            Storage::disk($oldAvatar->disk)->delete($oldAvatar->path);
        }

        if ($emailChanged && $user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }
    }
}
