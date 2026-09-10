<?php

namespace App\Services\Admin;

use App\Models\EvaluationRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ManageUsers
{
    public function save(User $actor, ?int $id, array $data): User
    {
        AdminAccess::authorize($actor);

        return DB::transaction(function () use ($actor, $id, $data) {
            // Serialize privilege changes, including two administrators editing each other.
            $users = User::query()->whereIn('id', array_filter([$actor->id, $id]))->orderBy('id')->lockForUpdate()->get();
            $user = $id ? $users->firstWhere('id', $id) : new User;
            abort_unless($user, 404);
            $values = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)],
                'password' => [$id ? 'nullable' : 'required', 'string', Password::min(12), 'confirmed'],
                'is_admin' => ['required', 'boolean'],
                'verified' => ['required', 'boolean'],
                'suspended' => ['required', 'boolean'],
            ])->validate();
            AdminAccess::authorize($users->firstWhere('id', $actor->id));
            if ($id === $actor->id && (! $values['is_admin'] || ! $values['verified'] || $values['suspended'])) {
                throw ValidationException::withMessages(['is_admin' => 'لا يمكنك تعطيل حساب الإدارة الذي تستخدمه أو سحب صلاحياته.']);
            }
            if ($values['is_admin'] && ! $values['verified']) {
                throw ValidationException::withMessages(['verified' => 'تتطلب صلاحية الإدارة حساباً موثّقاً.']);
            }
            $user->forceFill([
                'name' => $values['name'], 'email' => mb_strtolower($values['email']),
                'is_admin' => $values['is_admin'],
                'email_verified_at' => $values['verified'] ? ($user->email_verified_at ?? now()) : null,
                'suspended_at' => $values['suspended'] ? ($user->suspended_at ?? now()) : null,
            ]);
            if (! empty($values['password'])) {
                $user->password = $values['password'];
                $user->remember_token = Str::random(60);
            }
            $user->save();
            AdminAudit::record($actor->id, $id ? 'user.update' : 'user.create', 'users', $user->id);

            return $user;
        });
    }

    public function delete(User $actor, int $id): void
    {
        AdminAccess::authorize($actor);
        DB::transaction(function () use ($actor, $id) {
            $users = User::query()->whereIn('id', array_filter([$actor->id, $id]))->orderBy('id')->lockForUpdate()->get();
            AdminAccess::authorize($users->firstWhere('id', $actor->id));
            $user = $users->firstWhere('id', $id);
            abort_unless($user, 404);
            if ($id === $actor->id) {
                throw ValidationException::withMessages(['delete' => 'لا يمكنك حذف حساب الإدارة الذي تستخدمه.']);
            }
            if (EvaluationRun::where('created_by', $id)->exists()) {
                throw ValidationException::withMessages(['delete' => 'هذا الحساب مرتبط بسجل تقييم محفوظ. استخدم تعطيل الحساب للحفاظ على توثيق النتائج.']);
            }
            if ($user->documents()->exists()) {
                throw ValidationException::withMessages(['delete' => 'احذف مستندات المستخدم من لوحة المستندات أولاً لتنظيف الملفات وvectors بأمان.']);
            }
            if ($user->conversations()->whereHas('messages', fn ($q) => $q->where('status', 'pending'))->exists()) {
                throw ValidationException::withMessages(['delete' => 'انتظر اكتمال الإجابات الجارية قبل حذف الحساب.']);
            }
            AdminAudit::record($actor->id, 'user.delete', 'users', $id);
            $user->delete();
        });
    }
}
