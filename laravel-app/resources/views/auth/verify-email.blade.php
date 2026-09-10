<x-layouts.auth
    title="التحقق من البريد"
    heading="تحقّق من بريدك الإلكتروني"
    description="أرسلنا رابط تحقق إلى بريدك. افتح الرابط لتفعيل حسابك والوصول إلى مساحة العمل."
>
    @if (session('status') === 'verification-link-sent')
        <div class="mb-6 rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-4 py-3 text-sm leading-6 text-cyan-400">
            تم إرسال رابط تحقق جديد إلى بريدك الإلكتروني.
        </div>
    @endif

    <div class="space-y-4">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <flux:button
                type="submit"
                variant="primary"
                class="w-full"
            >
                إعادة إرسال رابط التحقق
            </flux:button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <flux:button
                type="submit"
                variant="ghost"
                class="w-full"
            >
                تسجيل الخروج
            </flux:button>
        </form>
    </div>
</x-layouts.auth>