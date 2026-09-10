<article class="rounded-xl border border-white/10 bg-navy-900/70 p-6 sm:p-8">
    <header class="mb-6">
        <h2 class="text-xl font-semibold text-ice-100">
            المعلومات الشخصية
        </h2>

        <p class="mt-2 text-sm leading-6 text-mist-300">
            حدّث اسمك أو البريد الإلكتروني المرتبط بحسابك.
        </p>
    </header>

    @if (session('status') === 'profile-information-updated')
        <div class="mb-6 rounded-lg border border-cyan-400/20 bg-cyan-400/10 px-4 py-3 text-sm text-cyan-400">
            تم تحديث معلومات الحساب بنجاح.
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('user-profile-information.update') }}"
        class="space-y-5"
    >
        @csrf
        @method('PUT')

        <div>
            <flux:input
                name="name"
                type="text"
                label="الاسم"
                value="{{ old('name', auth()->user()->name) }}"
                autocomplete="name"
                required
            />

            @error('name', 'updateProfileInformation')
                <p class="mt-2 text-sm text-danger-300">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <div>
            <flux:input
                name="email"
                type="email"
                label="البريد الإلكتروني"
                value="{{ old('email', auth()->user()->email) }}"
                autocomplete="email"
                required
            />

            @error('email', 'updateProfileInformation')
                <p class="mt-2 text-sm text-danger-300">
                    {{ $message }}
                </p>
            @enderror
        </div>

        <p class="text-sm leading-6 text-mist-300">
            عند تغيير البريد الإلكتروني، ستحتاج إلى التحقق من العنوان الجديد
            قبل العودة إلى مساحة العمل.
        </p>

        <flux:button type="submit" variant="primary">
            حفظ التغييرات
        </flux:button>
    </form>
</article>