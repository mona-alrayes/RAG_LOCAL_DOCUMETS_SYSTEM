<x-layouts.app :title="$documentDetail->summary->title ?: $documentDetail->summary->originalName">
    @php
        $document = $documentDetail->summary;

        $displayTitle = $document->title ?: $document->originalName;

        $fileSize = match (true) {
            $document->fileSize >= 1024 * 1024 =>
                number_format($document->fileSize / (1024 * 1024), 1) . ' MB',

            $document->fileSize >= 1024 =>
                number_format($document->fileSize / 1024, 1) . ' KB',

            default =>
                number_format($document->fileSize) . ' B',
        };

        $activeRun = $document->activeRun;
        $latestAttempt = $document->latestAttempt;

        $activeAndLatestAreDifferent =
            $activeRun !== null
            && $latestAttempt !== null
            && $activeRun->id !== $latestAttempt->id;

        $securityScanEnabled = (bool) config(
            'security.document_security_scan.enabled',
            true,
        );

        $securityScanState = match (true) {
            ! $securityScanEnabled => null,

            $document->availability->value === 'pending' =>
                'pending',

            $document->availability->value === 'scanning' =>
                'scanning',

            $document->availability->value === 'infected' =>
                'infected',

            $latestAttempt !== null =>
                'clean',

            in_array(
                $document->availability->value,
                ['queued', 'processing', 'indexing', 'ready'],
                true,
            ) => 'clean',

            $document->availability->value === 'failed' =>
                'failed',

            default => null,
        };

        [$securityTitle, $securityDescription, $securityClasses] =
            match ($securityScanState) {
                'pending' => [
                    'بانتظار بدء الفحص الأمني',
                    'سيتم فحص الملف قبل السماح ببدء المعالجة.',
                    'border-slate-400/20 bg-slate-400/10 text-slate-200',
                ],

                'scanning' => [
                    'جارٍ الفحص الأمني…',
                    'يتم فحص الملف حاليًا قبل نقله إلى مرحلة المعالجة.',
                    'border-cyan-400/20 bg-cyan-400/10 text-cyan-200',
                ],

                'clean' => [
                    'تم اجتياز الفحص الأمني',
                    'لم يرصد الفحص تهديدًا معروفًا، وتم السماح بمتابعة المعالجة.',
                    'border-emerald-400/20 bg-emerald-400/10 text-emerald-200',
                ],

                'infected' => [
                    'تم رفض الملف أمنيًا',
                    'رصد الفحص الأمني تهديدًا في الملف، لذلك لن تتم معالجته.',
                    'border-rose-400/25 bg-rose-400/10 text-rose-200',
                ],

                'failed' => [
                    'تعذر إكمال الفحص الأمني',
                    'لم يكتمل الفحص الأمني بنجاح، لذلك لم تتم متابعة المعالجة.',
                    'border-red-400/20 bg-red-400/10 text-red-200',
                ],

                default => [null, null, null],
            };
    @endphp

    <div class="min-w-0 space-y-8">
        {{-- Header --}}
        <header class="min-w-0 space-y-5">
            <a
                href="{{ route('documents.index') }}"
                class="inline-flex min-h-10 items-center gap-2 rounded-lg text-sm font-semibold text-cyan-400 transition hover:text-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400"
            >
                <span aria-hidden="true">←</span>
                العودة إلى الوثائق
            </a>

            <div class="flex min-w-0 flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-cyan-400">
                        تفاصيل الوثيقة
                    </p>

                    <h1 class="mt-2 break-words text-2xl font-bold text-ice-100 sm:text-3xl">
                        {{ $displayTitle }}
                    </h1>

                    @if ($document->title)
                        <p class="mt-2 break-all text-sm leading-6 text-mist-400">
                            {{ $document->originalName }}
                        </p>
                    @endif

                    <div class="mt-4">
                        <x-documents.status-indicator
                            :availability="$document->availability"
                        />
                    </div>
                </div>

                <div class="shrink-0 self-start">
                    <x-documents.actions-menu
                        :document="$document"
                        :available-processing-profiles="$availableProcessingProfiles"
                    />
                </div>
            </div>
        </header>

        {{-- Flash messages --}}
        @if (session('success'))
            <div
                role="status"
                class="break-words rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-3 text-sm leading-6 text-emerald-200"
            >
                {{ session('success') }}
            </div>
        @endif

        @if (session('warning'))
            <div
                role="status"
                class="break-words rounded-xl border border-amber-400/20 bg-amber-400/10 px-4 py-3 text-sm leading-6 text-amber-200"
            >
                {{ session('warning') }}
            </div>
        @endif

        @if (session('error'))
            <div
                role="alert"
                class="break-words rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm leading-6 text-red-200"
            >
                {{ session('error') }}
            </div>
        @endif

        {{-- Security scan state --}}
        @if ($securityScanState !== null)
            <section
                data-document-security-scan-state="{{ $securityScanState }}"
                class="rounded-2xl border p-4 sm:p-5 {{ $securityClasses }}"
                aria-label="حالة الفحص الأمني"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="mt-1 flex size-7 shrink-0 items-center justify-center rounded-full border border-current/20 bg-current/5"
                        aria-hidden="true"
                    >
                        @if ($securityScanState === 'clean')
                            ✓
                        @elseif ($securityScanState === 'infected' || $securityScanState === 'failed')
                            !
                        @else
                            <span
                                class="size-2 rounded-full bg-current {{ $securityScanState === 'scanning' ? 'animate-pulse' : '' }}"
                            ></span>
                        @endif
                    </span>

                    <div class="min-w-0">
                        <p class="font-semibold">
                            {{ $securityTitle }}
                        </p>

                        <p class="mt-1 text-sm leading-7 opacity-80">
                            {{ $securityDescription }}
                        </p>
                    </div>
                </div>
            </section>
        @endif

        {{-- Reprocessing state --}}
        @if ($document->reprocessingInProgress && $activeAndLatestAreDifferent)
            <div
                class="rounded-2xl border border-amber-400/20 bg-amber-400/10 p-4 sm:p-5"
            >
                <p class="font-semibold text-amber-200">
                    إعادة معالجة جديدة جارية
                </p>

                <p class="mt-2 text-sm leading-7 text-amber-100/80">
                    النسخة المعالجة السابقة ما زالت هي النسخة الفعالة حاليًا
                    إلى أن تنجح محاولة إعادة المعالجة الجديدة.
                </p>
            </div>
        @endif

        @if (
            $document->safeFailure !== null
            && $activeAndLatestAreDifferent
            && $document->availability->value === 'ready'
        )
            <div
                class="rounded-2xl border border-red-400/20 bg-red-400/10 p-4 sm:p-5"
            >
                <p class="font-semibold text-red-200">
                    فشلت آخر محاولة لإعادة المعالجة
                </p>

                <p class="mt-2 text-sm leading-7 text-red-100/80">
                    {{ $document->failureMessage() }}
                    النسخة السابقة ما زالت فعالة ومتاحة للاستخدام.
                </p>
            </div>
        @elseif ($document->safeFailure !== null)
            <div
                class="break-words rounded-2xl border border-red-400/20 bg-red-400/10 p-4 text-sm leading-6 text-red-200 sm:p-5"
            >
                {{ $document->failureMessage() }}
            </div>
        @endif

        {{-- Document summary --}}
        <section
            class="min-w-0 rounded-2xl border border-white/10 bg-navy-900/70 p-4 sm:p-6"
            aria-labelledby="document-summary-title"
        >
            <h2
                id="document-summary-title"
                class="text-lg font-semibold text-ice-100"
            >
                معلومات الوثيقة
            </h2>

            <dl class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div class="min-w-0">
                    <dt class="text-sm text-mist-400">
                        اسم الملف
                    </dt>

                    <dd class="mt-2 break-all text-sm font-medium leading-6 text-ice-100">
                        {{ $document->originalName }}
                    </dd>
                </div>

                <div class="min-w-0">
                    <dt class="text-sm text-mist-400">
                        نوع الملف
                    </dt>

                    <dd class="mt-2 text-sm font-medium text-ice-100">
                        {{ strtoupper($document->fileType->value) }}
                    </dd>
                </div>

                <div class="min-w-0">
                    <dt class="text-sm text-mist-400">
                        الحجم
                    </dt>

                    <dd class="mt-2 text-sm font-medium text-ice-100">
                        {{ $fileSize }}
                    </dd>
                </div>

                <div class="min-w-0">
                    <dt class="text-sm text-mist-400">
                        الحالة الحالية
                    </dt>

                    <dd class="mt-2">
                        <x-documents.status-indicator
                            :availability="$document->availability"
                        />
                    </dd>
                </div>

                <div class="min-w-0">
                    <dt class="text-sm text-mist-400">
                        طريقة المعالجة الفعالة
                    </dt>

                    <dd class="mt-2 break-words text-sm font-medium text-ice-100">
                        @if ($activeRun !== null)
                            {{ __('documents.processing_run.profile.' . $activeRun->profile->value) }}
                        @else
                            —
                        @endif
                    </dd>
                </div>

                <div class="min-w-0">
                    <dt class="text-sm text-mist-400">
                        تاريخ الإضافة
                    </dt>

                    <dd class="mt-2 text-sm font-medium text-ice-100">
                        <time datetime="{{ $document->createdAt->toIso8601String() }}">
                            {{ $document->createdAt->format('Y-m-d H:i') }}
                        </time>
                    </dd>
                </div>
            </dl>
        </section>

        {{-- Active and latest processing state --}}
        <section
            class="grid min-w-0 gap-4 lg:grid-cols-2"
            aria-label="حالة المعالجة الحالية"
        >
            <article
                class="min-w-0 rounded-2xl border border-white/10 bg-navy-900/70 p-4 sm:p-5"
            >
                <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold text-ice-100">
                        النسخة الفعالة
                    </h2>

                    @if ($activeRun !== null)
                        <span
                            class="shrink-0 rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-300"
                        >
                            فعالة
                        </span>
                    @endif
                </div>

                @if ($activeRun !== null)
                    <dl class="mt-5 space-y-4 text-sm">
                        <div class="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <dt class="text-mist-400">
                                طريقة المعالجة
                            </dt>

                            <dd class="break-words font-medium text-ice-100 sm:text-end">
                                {{ __('documents.processing_run.profile.' . $activeRun->profile->value) }}
                            </dd>
                        </div>

                        <div class="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <dt class="text-mist-400">
                                الحالة
                            </dt>

                            <dd class="break-words font-medium text-ice-100 sm:text-end">
                                {{ __('documents.processing_run.status.' . $activeRun->status->value) }}
                            </dd>
                        </div>

                        <div class="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <dt class="text-mist-400">
                                نوع العملية
                            </dt>

                            <dd class="break-words font-medium text-ice-100 sm:text-end">
                                {{ __('documents.processing_run.kind.' . $activeRun->kind->value) }}
                            </dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-4 text-sm leading-7 text-mist-400">
                        لا توجد نسخة معالجة فعالة حتى الآن.
                    </p>
                @endif
            </article>

            <article
                class="min-w-0 rounded-2xl border border-white/10 bg-navy-900/70 p-4 sm:p-5"
            >
                <h2 class="font-semibold text-ice-100">
                    آخر محاولة معالجة
                </h2>

                @if ($latestAttempt !== null)
                    <dl class="mt-5 space-y-4 text-sm">
                        <div class="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <dt class="text-mist-400">
                                طريقة المعالجة
                            </dt>

                            <dd class="break-words font-medium text-ice-100 sm:text-end">
                                {{ __('documents.processing_run.profile.' . $latestAttempt->profile->value) }}
                            </dd>
                        </div>

                        <div class="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <dt class="text-mist-400">
                                الحالة
                            </dt>

                            <dd class="break-words font-medium text-ice-100 sm:text-end">
                                {{ __('documents.processing_run.status.' . $latestAttempt->status->value) }}
                            </dd>
                        </div>

                        <div class="flex min-w-0 flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <dt class="text-mist-400">
                                نوع العملية
                            </dt>

                            <dd class="break-words font-medium text-ice-100 sm:text-end">
                                {{ __('documents.processing_run.kind.' . $latestAttempt->kind->value) }}
                            </dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-4 text-sm leading-7 text-mist-400">
                        لم تبدأ أي محاولة معالجة بعد.
                    </p>
                @endif
            </article>
        </section>

        {{-- Processing timeline --}}
        <section
            class="min-w-0 rounded-2xl border border-white/10 bg-navy-900/70 p-4 sm:p-6"
            aria-labelledby="processing-timeline-title"
        >
            <div>
                <h2
                    id="processing-timeline-title"
                    class="text-lg font-semibold text-ice-100"
                >
                    سجل المعالجة
                </h2>

                <p class="mt-2 text-sm leading-7 text-mist-400">
                    تاريخ محاولات معالجة الوثيقة والمراحل التي تم تسجيلها فعليًا.
                </p>
            </div>

            @if (count($documentDetail->timeline) > 0)
                <div class="mt-6 space-y-5">
                    @foreach ($documentDetail->timeline as $run)
                        @php
                            $stages = [
                                [
                                    'label' => 'أضيفت إلى قائمة الانتظار',
                                    'time' => $run->queuedAt,
                                ],
                                [
                                    'label' => 'بدأت المعالجة',
                                    'time' => $run->startedAt,
                                ],
                                [
                                    'label' => 'بدأت الفهرسة',
                                    'time' => $run->indexingStartedAt,
                                ],
                                [
                                    'label' => 'اكتملت المعالجة',
                                    'time' => $run->indexedAt,
                                ],
                                [
                                    'label' => 'فشلت المعالجة',
                                    'time' => $run->failedAt,
                                ],
                            ];
                        @endphp

                        <article
                            class="min-w-0 rounded-xl border border-white/10 bg-navy-950/60 p-4 sm:p-5"
                        >
                            <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                                        <h3 class="break-words font-semibold text-ice-100">
                                            {{ __('documents.processing_run.kind.' . $run->kind->value) }}
                                        </h3>

                                        @if ($run->isActive)
                                            <span
                                                class="shrink-0 rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2.5 py-1 text-xs font-semibold text-emerald-300"
                                            >
                                                النسخة الفعالة
                                            </span>
                                        @endif
                                    </div>

                                    <p class="mt-2 break-words text-sm text-mist-400">
                                        {{ __('documents.processing_run.profile.' . $run->profile->value) }}
                                    </p>
                                </div>

                                <span class="break-words text-sm font-medium text-mist-200 sm:shrink-0">
                                    {{ __('documents.processing_run.status.' . $run->status->value) }}
                                </span>
                            </div>

                            @if ($run->totalPages !== null || $run->totalChunks !== null)
                                <dl class="mt-5 flex flex-wrap gap-6 text-sm">
                                    @if ($run->totalPages !== null)
                                        <div>
                                            <dt class="text-mist-400">
                                                الصفحات
                                            </dt>

                                            <dd class="mt-1 font-semibold text-ice-100">
                                                {{ number_format($run->totalPages) }}
                                            </dd>
                                        </div>
                                    @endif

                                    @if ($run->totalChunks !== null)
                                        <div>
                                            <dt class="text-mist-400">
                                                المقاطع
                                            </dt>

                                            <dd class="mt-1 font-semibold text-ice-100">
                                                {{ number_format($run->totalChunks) }}
                                            </dd>
                                        </div>
                                    @endif
                                </dl>
                            @endif

                            <ol class="mt-6 space-y-3 border-s border-white/10 ps-5">
                                @foreach ($stages as $stage)
                                    @if ($stage['time'] !== null)
                                        <li class="relative min-w-0">
                                            <span
                                                class="absolute -start-[1.55rem] top-1.5 size-2 rounded-full bg-cyan-400"
                                                aria-hidden="true"
                                            ></span>

                                            <p class="break-words text-sm font-medium text-ice-100">
                                                {{ $stage['label'] }}
                                            </p>

                                            <time
                                                datetime="{{ $stage['time']->toIso8601String() }}"
                                                class="mt-1 block break-words text-xs text-mist-400"
                                            >
                                                {{ $stage['time']->format('Y-m-d H:i:s') }}
                                            </time>
                                        </li>
                                    @endif
                                @endforeach
                            </ol>

                            @if (! empty($run->stageTimingsMs))
                                <div class="mt-6 min-w-0">
                                    <h4 class="text-sm font-semibold text-ice-100">
                                        أزمنة المراحل
                                    </h4>

                                    <dl class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach ($run->stageTimingsMs as $stage => $milliseconds)
                                            <div
                                                class="min-w-0 rounded-lg border border-white/10 bg-white/5 px-3 py-2"
                                            >
                                                <dt class="break-all text-xs leading-5 text-mist-400">
                                                    {{ $stage }}
                                                </dt>

                                                <dd class="mt-1 break-words text-sm font-medium text-ice-100">
                                                    {{ number_format($milliseconds) }} ms
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endif

                            @if (! empty($run->warnings))
                                <div
                                    class="mt-6 min-w-0 rounded-xl border border-amber-400/20 bg-amber-400/10 p-4"
                                >
                                    <p class="text-sm font-semibold text-amber-200">
                                        تحذيرات أثناء المعالجة
                                    </p>

                                    <ul class="mt-3 space-y-2 text-sm leading-6 text-amber-100/80">
                                        @foreach ($run->warnings as $warning)
                                            <li class="break-all">
                                                {{ $warning['code'] }}

                                                @if ($warning['stage'] !== null)
                                                    —
                                                    {{ $warning['stage'] }}
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($run->failedAt !== null)
                                <div
                                    class="mt-6 break-words rounded-xl border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm leading-6 text-red-200"
                                >
                                    تعذر إكمال محاولة المعالجة هذه.
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @else
                <div
                    class="mt-6 rounded-xl border border-dashed border-white/15 px-4 py-8 text-center text-sm leading-6 text-mist-400 sm:px-5"
                >
                    لا توجد محاولات معالجة مسجلة حتى الآن.
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>