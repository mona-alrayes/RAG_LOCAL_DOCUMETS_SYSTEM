<article class="rounded-xl border border-white/10 bg-navy-900/70 p-6 sm:p-8">
    <header class="mb-6">
        <h2 class="text-xl font-semibold text-ice-100">
            تغيير كلمة المرور
        </h2>

        <p class="mt-2 text-sm leading-6 text-mist-300">
            استخدم كلمة مرور قوية ومختلفة عن كلمة المرور الحالية.
        </p>
    </header>

    @if (session('status') === 'password-updated')
        <div class="mb-6 rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-4 py-3 text-sm text-cyan-400">
            تم تحديث كلمة المرور بنجاح.
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('user-password.update') }}"
        class="space-y-5"
    >
        @csrf
        @method('PUT')

        <div>
            <flux:input
                name="current_password"
                type="password"
                label="كلمة المرور الحالية"
                autocomplete="current-password"
                required
                viewable
            />

            @error('current_password', 'updatePassword')
                <p class="mt-2 text-sm text-danger-300">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div>
            <flux:input
                name="password"
                type="password"
                label="كلمة المرور الجديدة"
                autocomplete="new-password"
                required
                viewable
            />

            @error('password', 'updatePassword')
                <p class="mt-2 text-sm text-danger-300">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div>
            <flux:input
                name="password_confirmation"
                type="password"
                label="تأكيد كلمة المرور الجديدة"
                autocomplete="new-password"
                required
                viewable
            />

            @error('password_confirmation', 'updatePassword')
                <p class="mt-2 text-sm text-danger-300">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <flux:button type="submit" variant="primary">
            تحديث كلمة المرور
        </flux:button>
    </form>
</article>