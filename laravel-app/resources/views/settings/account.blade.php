<x-layouts.app title="إعدادات الحساب">
    <header class="mb-8">
        <p class="text-sm font-semibold text-cyan-400">
            إعدادات الحساب
        </p>

        <h1 class="mt-2 text-3xl font-bold text-ice-100">
            الملف الشخصي والأمان
        </h1>

        <p class="mt-3 text-mist-300">
            حدّث بيانات حسابك أو غيّر كلمة المرور.
        </p>
    </header>

    <div class="grid max-w-5xl gap-6 lg:grid-cols-2">
        @include('settings.partials.update-profile-information-form')
        @include('settings.partials.update-password-form')
    </div>
</x-layouts.app>