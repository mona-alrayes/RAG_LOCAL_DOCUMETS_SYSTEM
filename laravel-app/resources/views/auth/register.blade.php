<x-layouts.auth
    title="إنشاء حساب"
    heading="إنشاء حساب جديد"
    description="أنشئ حسابك للبدء بإدارة وثائقك والاستعلام عنها."
>
    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <flux:input
            name="name"
            type="text"
            label="الاسم"
            value="{{ old('name') }}"
            autocomplete="name"
            required
            autofocus
        />

        <flux:input
            name="email"
            type="email"
            label="البريد الإلكتروني"
            value="{{ old('email') }}"
            placeholder="name@example.com"
            autocomplete="email"
            required
        />

        <flux:input
            name="password"
            type="password"
            label="كلمة المرور"
            autocomplete="new-password"
            required
            viewable
        />

        <flux:input
            name="password_confirmation"
            type="password"
            label="تأكيد كلمة المرور"
            autocomplete="new-password"
            required
            viewable
        />

        <flux:button
            type="submit"
            variant="primary"
            class="w-full"
        >
            إنشاء الحساب
        </flux:button>
    </form>

    <p class="mt-6 text-center text-sm text-mist-300">
        لديك حساب بالفعل؟

        <flux:link href="{{ route('login') }}">
            تسجيل الدخول
        </flux:link>
    </p>
</x-layouts.auth>