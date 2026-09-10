<x-layouts.app title="الوثائق">
    @php
        $hasActiveFilters =
            request()->filled('search')
            || request()->filled('status')
            || request()->filled('file_type');
    @endphp

    <div class="space-y-8">
        {{-- Header --}}
        <header class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-cyan-400">
                    إدارة الوثائق
                </p>

                <h1 class="mt-2 text-3xl font-bold text-ice-100">
                    وثائقي
                </h1>

                <p class="mt-3 max-w-2xl text-sm leading-7 text-mist-300">
                    استعرض ملفاتك، تابع حالة المعالجة، وابحث عن الوثيقة التي تحتاجها.
                </p>
            </div>

            <div class="shrink-0 text-sm text-mist-300">
                {{ $documents->total() }} وثيقة
            </div>
        </header>

        {{-- Flash messages --}}
        @if (session('success'))
            <div
                role="status"
                class="rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200"
            >
                {{ session('success') }}
            </div>
        @endif

        @if (session('warning'))
            <div
                role="status"
                class="rounded-xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm text-amber-200"
            >
                {{ session('warning') }}
            </div>
        @endif

        @if (session('error'))
            <div
                role="alert"
                class="rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200"
            >
                {{ session('error') }}
            </div>
        @endif

        @persist('documents-upload-controls')
        {{-- Upload document --}}
        <section
            class="rounded-2xl border border-white/10 bg-navy-900/70 p-4 sm:p-5"
            aria-labelledby="upload-document-title"
        >
            <div class="mb-5">
                <h2
                    id="upload-document-title"
                    class="text-lg font-semibold text-ice-100"
                >
                    رفع وثيقة جديدة
                </h2>

                <p class="mt-2 text-sm leading-7 text-mist-300">
                    اختر ملفًا واحدًا بصيغة PDF أو DOCX أو TXT، ثم اختر طريقة المعالجة المتاحة.
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('documents.store') }}"
                enctype="multipart/form-data"
                class="space-y-5"
            >
                @csrf

                <div>
                    <label
                        for="document"
                        class="mb-2 block text-sm font-medium text-ice-100"
                    >
                        الملف
                    </label>

                    <input
                        id="document"
                        name="document"
                        type="file"
                        accept=".pdf,.docx,.txt"
                        required
                        @error('document')
                            aria-invalid="true"
                            aria-describedby="document-error"
                        @enderror
                        class="block w-full rounded-xl border border-white/10 bg-navy-950 px-4 py-3 text-sm text-mist-200 file:me-4 file:rounded-lg file:border-0 file:bg-cyan-400 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-navy-950 hover:file:bg-cyan-300"
                    >

                    @error('document')
                        <p
                            id="document-error"
                            role="alert"
                            class="mt-2 text-sm text-red-300"
                        >
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <fieldset
                    @error('processing_profile')
                        aria-describedby="processing-profile-error"
                    @enderror
                >
                    <legend class="mb-3 text-sm font-medium text-ice-100">
                        طريقة المعالجة
                    </legend>

                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach (\App\Enums\ProcessingProfile::cases() as $profile)
                            @php
                                $isAvailable = in_array(
                                    $profile,
                                    $availableProcessingProfiles,
                                    true,
                                );
                            @endphp

                            <label
                                class="flex min-w-0 items-start gap-3 rounded-xl border p-4 transition
                                    {{ $isAvailable
                                        ? 'cursor-pointer border-white/10 bg-navy-950 hover:border-cyan-400/40'
                                        : 'cursor-not-allowed border-white/5 bg-navy-950/50 opacity-50' }}"
                            >
                                <input
                                    type="radio"
                                    name="processing_profile"
                                    value="{{ $profile->value }}"
                                    required
                                    @disabled(! $isAvailable)
                                    @checked(
                                        old('processing_profile') === $profile->value
                                        && $isAvailable
                                    )
                                    class="mt-1 shrink-0"
                                >

                                <span class="min-w-0">
                                    <span class="block break-words font-medium text-ice-100">
                                        {{ __('documents.processing_run.profile.' . $profile->value) }}
                                    </span>

                                    @unless ($isAvailable)
                                        <span class="mt-1 block text-xs text-mist-400">
                                            غير متاح حاليًا
                                        </span>
                                    @endunless
                                </span>
                            </label>
                        @endforeach
                    </div>

                    @error('processing_profile')
                        <p
                            id="processing-profile-error"
                            role="alert"
                            class="mt-2 text-sm text-red-300"
                        >
                            {{ $message }}
                        </p>
                    @enderror
                </fieldset>

                @if (empty($availableProcessingProfiles))
                    <p
                        role="status"
                        class="rounded-xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm text-amber-200"
                    >
                        لا توجد طريقة معالجة متاحة حاليًا. يرجى المحاولة لاحقًا.
                    </p>
                @endif

                <button
                    type="submit"
                    @disabled(empty($availableProcessingProfiles))
                    class="inline-flex min-h-10 w-full items-center justify-center rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-semibold text-navy-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                >
                    رفع الوثيقة
                </button>
            </form>
        </section>

        @endpersist

        @persist('documents-filter-controls')
        {{-- Filters --}}
        <section
            class="rounded-2xl border border-white/10 bg-navy-900/70 p-4 sm:p-5"
            aria-label="تصفية الوثائق"
        >
            <form
                method="GET"
                action="{{ route('documents.index') }}"
                class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_180px_160px_auto]"
            >
                <div class="min-w-0">
                    <label
                        for="search"
                        class="mb-2 block text-sm font-medium text-ice-100"
                    >
                        البحث
                    </label>

                    <input
                        id="search"
                        name="search"
                        type="search"
                        value="{{ request('search') }}"
                        placeholder="ابحث بالعنوان أو اسم الملف..."
                        class="w-full rounded-xl border border-white/10 bg-navy-950 px-4 py-2.5 text-sm text-ice-100 outline-none transition placeholder:text-mist-500 focus:border-cyan-400/60 focus:ring-2 focus:ring-cyan-400/10"
                    >
                </div>

                <div class="min-w-0">
                    <label
                        for="status"
                        class="mb-2 block text-sm font-medium text-ice-100"
                    >
                        الحالة
                    </label>

                    <select
                        id="status"
                        name="status"
                        class="w-full rounded-xl border border-white/10 bg-navy-950 px-4 py-2.5 text-sm text-ice-100 outline-none transition focus:border-cyan-400/60 focus:ring-2 focus:ring-cyan-400/10"
                    >
                        <option value="">كل الحالات</option>

                        @foreach (\App\Enums\DocumentStatus::cases() as $status)
                            <option
                                value="{{ $status->value }}"
                                @selected(request('status') === $status->value)
                            >
                                {{ $status->value }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="min-w-0">
                    <label
                        for="file_type"
                        class="mb-2 block text-sm font-medium text-ice-100"
                    >
                        نوع الملف
                    </label>

                    <select
                        id="file_type"
                        name="file_type"
                        class="w-full rounded-xl border border-white/10 bg-navy-950 px-4 py-2.5 text-sm text-ice-100 outline-none transition focus:border-cyan-400/60 focus:ring-2 focus:ring-cyan-400/10"
                    >
                        <option value="">كل الأنواع</option>

                        @foreach (\App\Enums\FileType::cases() as $fileType)
                            <option
                                value="{{ $fileType->value }}"
                                @selected(request('file_type') === $fileType->value)
                            >
                                {{ strtoupper($fileType->value) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row lg:items-end">
                    <button
                        type="submit"
                        class="inline-flex min-h-10 items-center justify-center rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-semibold text-navy-950 transition hover:bg-cyan-300"
                    >
                        تطبيق
                    </button>

                    @if ($hasActiveFilters)
                        <a
                            href="{{ route('documents.index') }}"
                            class="inline-flex min-h-10 items-center justify-center rounded-xl border border-white/10 px-4 py-2.5 text-sm font-medium text-mist-300 transition hover:bg-white/5 hover:text-ice-100"
                        >
                            مسح
                        </a>
                    @endif
                </div>
            </form>
        </section>

        @endpersist

        {{-- Documents --}}
        @if ($documents->isNotEmpty())
            <section class="space-y-3" aria-label="قائمة الوثائق">
                @foreach ($documents as $document)
                    @php
                        $displayTitle = $document->title ?: $document->originalName;

                        $fileSize = match (true) {
                            $document->fileSize >= 1024 * 1024 =>
                                number_format($document->fileSize / (1024 * 1024), 1) . ' MB',

                            $document->fileSize >= 1024 =>
                                number_format($document->fileSize / 1024, 1) . ' KB',

                            default =>
                                number_format($document->fileSize) . ' B',
                        };
                    @endphp

                    <article
                        class="group rounded-2xl border border-white/10 bg-navy-900/70 p-4 transition hover:border-white/20 hover:bg-navy-900 sm:p-5"
                    >
                        <div class="flex min-w-0 items-start gap-4">
                            <div
                                class="hidden size-12 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-xs font-bold text-cyan-300 sm:flex"
                                aria-hidden="true"
                            >
                                {{ strtoupper($document->fileType->value) }}
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex min-w-0 items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <a
                                            href="{{ route('documents.show', $document->id) }}"
                                            class="block break-words font-semibold text-ice-100 transition hover:text-cyan-300"
                                        >
                                            {{ $displayTitle }}
                                        </a>

                                        @if ($document->title)
                                            <p
                                                class="mt-1 break-all text-sm text-mist-400"
                                                title="{{ $document->originalName }}"
                                            >
                                                {{ $document->originalName }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="shrink-0">
                                        <x-documents.actions-menu
                                            :document="$document"
                                            :available-processing-profiles="$availableProcessingProfiles"
                                        />
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-3 text-xs text-mist-300">
                                    <span class="font-medium text-mist-200">
                                        {{ strtoupper($document->fileType->value) }}
                                    </span>

                                    <span>
                                        {{ $fileSize }}
                                    </span>

                                    <x-documents.status-indicator
                                        :availability="$document->availability"
                                    />

                                    <span>
                                        أضيفت
                                        <time datetime="{{ $document->createdAt->toIso8601String() }}">
                                            {{ $document->createdAt->format('Y-m-d') }}
                                        </time>
                                    </span>
                                </div>

                                @if ($document->reprocessingInProgress)
                                    <div
                                        class="mt-4 inline-flex max-w-full items-center gap-2 rounded-lg border border-amber-400/20 bg-amber-400/10 px-3 py-1.5 text-xs font-medium text-amber-300"
                                    >
                                        <span
                                            class="size-2 shrink-0 rounded-full bg-amber-400"
                                            aria-hidden="true"
                                        ></span>

                                        <span class="break-words">
                                            إعادة المعالجة جارية
                                        </span>
                                    </div>
                                @endif

                                @if ($document->safeFailure !== null)
                                    <p
                                        class="mt-4 break-words rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-200"
                                    >
                                        {{ __('documents.failure.processing_failed') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </section>

            @if ($documents->hasPages())
                <div class="min-w-0 overflow-x-auto pt-2">
                    {{ $documents->links() }}
                </div>
            @endif
        @elseif (! $hasAnyDocuments)
            <section
                class="rounded-2xl border border-dashed border-white/15 bg-navy-900/50 px-4 py-12 text-center sm:px-6 sm:py-14"
            >
                <div
                    class="mx-auto flex size-14 items-center justify-center rounded-2xl border border-white/10 bg-white/5 text-cyan-300"
                    aria-hidden="true"
                >
                    <span class="text-xl font-bold">+</span>
                </div>

                <h2 class="mt-5 text-lg font-semibold text-ice-100">
                    لا توجد وثائق حتى الآن
                </h2>

                <p class="mx-auto mt-2 max-w-md text-sm leading-7 text-mist-300">
                    ستظهر الوثائق التي تضيفها هنا مع حالة المعالجة والإجراءات المتاحة لكل وثيقة.
                </p>
            </section>
        @else
            <section
                class="rounded-2xl border border-dashed border-white/15 bg-navy-900/50 px-4 py-12 text-center sm:px-6 sm:py-14"
            >
                <h2 class="text-lg font-semibold text-ice-100">
                    لا توجد نتائج مطابقة
                </h2>

                <p class="mx-auto mt-2 max-w-md text-sm leading-7 text-mist-300">
                    لم نجد وثائق تطابق البحث أو الفلاتر الحالية.
                </p>

                <a
                    href="{{ route('documents.index') }}"
                    class="mt-5 inline-flex min-h-10 items-center justify-center rounded-xl border border-cyan-400/30 px-4 py-2.5 text-sm font-semibold text-cyan-300 transition hover:bg-cyan-400/10"
                >
                    مسح جميع الفلاتر
                </a>
            </section>
        @endif
    </div>
</x-layouts.app>