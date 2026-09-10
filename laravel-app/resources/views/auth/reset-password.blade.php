<x-layouts.auth
    title="تعيين كلمة مرور جديدة"
    heading="كلمة مرور جديدة"
    description="اختر كلمة مرور قوية وجديدة لحسابك."
>
    <form
        method="POST"
        action="{{ route('password.update') }}"
        class="space-y-5"
    >
        @csrf

        <input
            type="hidden"
            name="token"
            value="{{ $request->route('token') }}"
        >

        <flux:input
            name="email"
            type="email"
            label="البريد الإلكتروني"
            value="{{ old('email', $request->email) }}"
            autocomplete="email"
            required
            readonly
        />

        <flux:input
            name="password"
            type="password"
            label="كلمة المرور الجديدة"
            autocomplete="new-password"
            required
            autofocus
            viewable
        />

        <flux:input
            name="password_confirmation"
            type="password"
            label="تأكيد كلمة المرور الجديدة"
            autocomplete="new-password"
            required
            viewable
        />

        <flux:button
            type="submit"
            variant="primary"
            class="w-full"
        >
            تحديث كلمة المرور
        </flux:button>
    </form>

    <p class="mt-6 text-center text-sm text-mist-300">
        <flux:link href="{{ route('login') }}">
            العودة إلى تسجيل الدخول
        </flux:link>
    </p>
</x-layouts.auth>