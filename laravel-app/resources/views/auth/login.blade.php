{{-- صفحة تسجيل الدخول --}}
<x-layouts.auth
    title="تسجيل الدخول"
    heading="مرحبًا بعودتك"
    description="أدخل بيانات حسابك للوصول إلى مساحة العمل."
>
    @if (session('status'))
        <div class="mb-5 rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-4 py-3 text-sm text-cyan-400">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <flux:input
            name="email"
            type="email"
            label="البريد الإلكتروني"
            value="{{ old('email') }}"
            placeholder="name@example.com"
            autocomplete="email"
            required
            autofocus
        />

        <flux:input
            name="password"
            type="password"
            label="كلمة المرور"
            autocomplete="current-password"
            required
            viewable
        />

        <div class="flex items-center justify-between gap-4">
            <flux:checkbox
                name="remember"
                label="تذكّرني"
            />

            <flux:link href="{{ route('password.request') }}">
                نسيت كلمة المرور؟
            </flux:link>
        </div>

        <flux:button
            type="submit"
            variant="primary"
            class="w-full"
        >
            تسجيل الدخول
        </flux:button>
    </form>

    <p class="mt-6 text-center text-sm text-mist-300">
        ليس لديك حساب؟

        <flux:link href="{{ route('register') }}">
            إنشاء حساب
        </flux:link>
    </p>
</x-layouts.auth>