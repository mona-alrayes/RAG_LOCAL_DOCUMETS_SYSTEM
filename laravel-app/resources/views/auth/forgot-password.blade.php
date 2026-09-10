<x-layouts.auth
    title="نسيت كلمة المرور"
    heading="استعادة كلمة المرور"
    description="أدخل بريدك الإلكتروني وسنرسل إليك رابطًا لإنشاء كلمة مرور جديدة."
>
    @if (session('status'))
        <div class="mb-6 rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-4 py-3 text-sm leading-6 text-cyan-400">
            {{ session('status') }}
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('password.email') }}"
        class="space-y-5"
    >
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

        <flux:button
            type="submit"
            variant="primary"
            class="w-full"
        >
            إرسال رابط الاستعادة
        </flux:button>
    </form>

    <p class="mt-6 text-center text-sm text-mist-300">
        تذكرت كلمة المرور؟

        <flux:link href="{{ route('login') }}">
            العودة إلى تسجيل الدخول
        </flux:link>
    </p>
</x-layouts.auth>