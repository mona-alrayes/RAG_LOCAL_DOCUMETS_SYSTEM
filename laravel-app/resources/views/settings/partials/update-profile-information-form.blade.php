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
        enctype="multipart/form-data"
        class="space-y-5"
    >
        @csrf
        @method('PUT')

        <div class="flex flex-col gap-4 rounded-xl border border-white/10 bg-navy-950/50 p-4 sm:flex-row sm:items-center">
            <x-user-avatar :user="auth()->user()" size="lg" />

            <div class="min-w-0 flex-1">
                <label for="avatar" class="block text-sm font-medium text-ice-100">
                    الصورة الشخصية
                </label>

                <p class="mt-1 text-xs leading-5 text-mist-300">
                    JPEG أو PNG أو WebP، بحجم أقصى 2MB.
                </p>

                <input
                    id="avatar"
                    name="avatar"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="mt-3 block w-full text-xs text-mist-300 file:me-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-cyan-400/10 file:px-3 file:py-2 file:font-semibold file:text-cyan-300 hover:file:bg-cyan-400/15"
                >

                @error('avatar', 'updateProfileInformation')
                    <p class="mt-2 text-sm text-danger-300">
                        {{ $message }}
                    </p>
                @enderror
            </div>
        </div>

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
