<x-filament-panels::page>
    @php
        $metrics = $evaluation?->metrics ?? [];
        $latency = $evaluation?->latency_summary ?? [];

        $formatScore = static fn ($value) =>
            $value !== null
                ? number_format((float) $value, 2)
                : '—';

        $formatMs = static fn ($value) =>
            $value !== null
                ? number_format((float) $value, 0) . ' ms'
                : '—';

        $scoreTone = static function ($value): string {
            if ($value === null) {
                return 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900';
            }

            if ($value >= 0.75) {
                return 'border-success-200 bg-success-50 dark:border-success-800 dark:bg-success-950/30';
            }

            if ($value >= 0.60) {
                return 'border-warning-200 bg-warning-50 dark:border-warning-800 dark:bg-warning-950/30';
            }

            return 'border-danger-200 bg-danger-50 dark:border-danger-800 dark:bg-danger-950/30';
        };

        $statusTone = match ($evaluation?->status) {
            'completed' => 'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300',
            'running' => 'bg-info-100 text-info-700 dark:bg-info-900/40 dark:text-info-300',
            'failed' => 'bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300',
            default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };

        $kpis = [
            [
                'label' => 'الاستدعاء',
                'technical' => 'Recall@K',
                'value' => $metrics['recall_at_k'] ?? null,
                'help' => 'يقيس نسبة المقاطع المرجعية الصحيحة التي استطاع النظام العثور عليها ضمن أفضل K نتائج.',
            ],
            [
                'label' => 'جودة الترتيب',
                'technical' => 'nDCG@K',
                'value' => $metrics['ndcg_at_k'] ?? null,
                'help' => 'يقيس جودة ترتيب المقاطع الصحيحة، بحيث تكون النتيجة أفضل عندما تظهر المقاطع المهمة في المراتب الأولى.',
            ],
            [
                'label' => 'صحة الإجابة',
                'technical' => 'Correctness',
                'value' => $metrics['correctness'] ?? null,
                'help' => 'يقارن إجابة النظام بالإجابة المرجعية لمعرفة مدى صحة المحتوى الناتج.',
            ],
            [
                'label' => 'الالتزام بالمصادر',
                'technical' => 'Faithfulness',
                'value' => $metrics['faithfulness'] ?? null,
                'help' => 'يقيس مدى اعتماد الإجابة على السياق المسترجع وعدم إضافة معلومات غير مدعومة من الوثائق.',
            ],
            [
                'label' => 'ارتباط الإجابة',
                'technical' => 'Answer Relevance',
                'value' => $metrics['answer_relevance'] ?? null,
                'help' => 'يقيس مدى تركيز الإجابة على السؤال المطلوب بدل تقديم معلومات جانبية أو غير مرتبطة.',
            ],
        ];
    @endphp

    <div
        class="space-y-6"
        dir="rtl"
        x-data="{ tab: 'overview' }"
    >
        {{-- Run selector --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div class="min-w-0 flex-1">
                    <label
                        for="evaluation-run"
                        class="mb-2 block text-sm font-semibold text-gray-700 dark:text-gray-200"
                    >
                        تشغيل التقييم
                    </label>

                    <select
                        id="evaluation-run"
                        wire:model.live="selectedRunId"
                        class="block w-full rounded-xl border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950"
                    >
                        @forelse($runs as $run)
                            <option value="{{ $run->id }}">
                                #{{ $run->id }}
                                — {{ $run->name }}
                                — {{ \App\Filament\Pages\EvaluationDashboard::statusLabel($run->status) }}
                            </option>
                        @empty
                            <option value="">
                                لا توجد عمليات تقييم
                            </option>
                        @endforelse
                    </select>
                </div>

                <div class="flex flex-wrap gap-3 text-sm font-medium">
                    <a
                        href="{{ \App\Filament\Pages\Evaluations::getUrl() }}"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-white transition hover:bg-primary-500"
                    >
                        + تقييم جديد
                    </a>

                    <a
                        href="{{ \App\Filament\Resources\EvaluationRunResource::getUrl() }}"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        سجل التقييمات
                    </a>
                </div>
            </div>
        </div>

        @if(!$evaluation)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-900">
                <div class="text-lg font-semibold">
                    لا توجد نتائج تقييم حتى الآن
                </div>

                <p class="mt-2 text-sm text-gray-500">
                    ابدأ تقييمًا جديدًا، وبعد إنشاء التشغيل ستظهر هنا مؤشرات جودة النظام.
                </p>
            </div>
        @else
            {{-- Run header --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-3">
                            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                                {{ $evaluation->name }}
                            </h2>

                            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusTone }}">
                                {{ \App\Filament\Pages\EvaluationDashboard::statusLabel($evaluation->status) }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-500">
                            <span>
                                المسار:
                                <strong class="text-gray-700 dark:text-gray-200">
                                    {{ \App\Filament\Pages\EvaluationDashboard::pipelineLabel(
                                        $evaluation->config_snapshot['pipeline'] ?? null
                                    ) }}
                                </strong>
                            </span>

                            <span>
                                K:
                                <strong class="text-gray-700 dark:text-gray-200">
                                    {{ $evaluation->k }}
                                </strong>
                            </span>

                            <span>
                                إصدار البيانات:
                                <strong class="text-gray-700 dark:text-gray-200">
                                    {{ $evaluation->dataset_version }}
                                </strong>
                            </span>

                            <span>
                                التاريخ:
                                <strong class="text-gray-700 dark:text-gray-200">
                                    {{ $evaluation->created_at?->format('Y-m-d H:i') }}
                                </strong>
                            </span>
                        </div>
                    </div>

                    <a
                        href="{{ \App\Filament\Pages\EvaluationResults::getUrl(['run' => $evaluation->id]) }}"
                        class="text-sm font-semibold text-primary-600 hover:text-primary-500"
                    >
                        عرض التفاصيل الكاملة ←
                    </a>
                </div>
            </div>

            {{-- KPI cards --}}
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach($kpis as $kpi)
                    <div class="rounded-2xl border p-5 shadow-sm {{ $scoreTone($kpi['value']) }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                                    {{ $kpi['label'] }}
                                </p>

                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ $kpi['technical'] }}
                                </p>
                            </div>

                            <span
                                title="{{ $kpi['help'] }}"
                                class="flex h-6 w-6 cursor-help items-center justify-center rounded-full border border-gray-300 text-xs font-bold text-gray-500 dark:border-gray-600"
                            >
                                ؟
                            </span>
                        </div>

                        <div class="mt-5 text-3xl font-bold tracking-tight text-gray-950 dark:text-white">
                            {{ $formatScore($kpi['value']) }}
                        </div>

                        @if($kpi['value'] !== null)
                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-full rounded-full bg-current text-primary-600"
                                    style="width: {{ min(100, max(0, ((float) $kpi['value']) * 100)) }}%"
                                ></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Tabs --}}
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="border-b border-gray-200 px-3 dark:border-gray-800">
                    <div class="flex gap-1 overflow-x-auto">
                        @foreach([
                            'overview' => 'نظرة عامة',
                            'retrieval' => 'جودة الاسترجاع',
                            'answer' => 'جودة الإجابة',
                            'comparison' => 'مقارنة المسارات',
                            'questions' => 'نتائج الأسئلة',
                        ] as $tabKey => $tabLabel)
                            <button
                                type="button"
                                @click="tab = '{{ $tabKey }}'"
                                :class="tab === '{{ $tabKey }}'
                                    ? 'border-primary-600 text-primary-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'"
                                class="whitespace-nowrap border-b-2 px-4 py-4 text-sm font-semibold transition"
                            >
                                {{ $tabLabel }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Overview --}}
                <div
                    x-show="tab === 'overview'"
                    x-cloak
                    class="space-y-6 p-6"
                >
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        @foreach([
                            'questions_count' => 'إجمالي الأسئلة',
                            'successful_questions' => 'ناجحة',
                            'failed_questions' => 'فاشلة',
                            'answerable_questions' => 'قابلة للإجابة',
                            'unanswerable_questions' => 'غير قابلة للإجابة',
                        ] as $field => $label)
                            <div class="rounded-xl bg-gray-50 p-4 dark:bg-gray-950">
                                <p class="text-xs font-medium text-gray-500">
                                    {{ $label }}
                                </p>

                                <p class="mt-2 text-2xl font-bold text-gray-950 dark:text-white">
                                    {{ $evaluation->{$field} ?? 0 }}
                                </p>
                            </div>
                        @endforeach
                    </div>

                    @if($diagnostic)
                        @php
                            $diagnosticTone = match (true) {
                                str_contains($diagnostic['title'], 'متوازن') =>
                                    'border-success-200 bg-success-50 dark:border-success-800 dark:bg-success-950/30',
                                str_contains($diagnostic['title'], 'مشكلة') =>
                                    'border-danger-200 bg-danger-50 dark:border-danger-800 dark:bg-danger-950/30',
                                default =>
                                    'border-warning-200 bg-warning-50 dark:border-warning-800 dark:bg-warning-950/30',
                            };
                        @endphp

                        <div class="rounded-xl border p-5 {{ $diagnosticTone }}">
                            <div class="flex gap-3">
                                <div class="mt-0.5 text-lg">●</div>

                                <div>
                                    <h3 class="font-bold text-gray-950 dark:text-white">
                                        {{ $diagnostic['title'] }}
                                    </h3>

                                    <p class="mt-1 text-sm leading-6 text-gray-700 dark:text-gray-300">
                                        {{ $diagnostic['message'] }}
                                    </p>

                                    <p class="mt-2 text-xs text-gray-500">
                                        التشخيص إرشادي ويهدف إلى تحديد المرحلة الأولى التي تستحق المراجعة.
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="grid gap-4 md:grid-cols-4">
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <p class="text-xs text-gray-500">زمن الاسترجاع</p>
                            <p class="mt-2 text-xl font-bold">
                                {{ $formatMs($latency['retrieval_mean_ms'] ?? null) }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <p class="text-xs text-gray-500">زمن توليد الإجابة</p>
                            <p class="mt-2 text-xl font-bold">
                                {{ $formatMs($latency['generation_mean_ms'] ?? null) }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <p class="text-xs text-gray-500">زمن التقييم الآلي</p>
                            <p class="mt-2 text-xl font-bold">
                                {{ $formatMs($latency['judge_mean_ms'] ?? null) }}
                            </p>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <p class="text-xs text-gray-500">متوسط الزمن الكلي</p>
                            <p class="mt-2 text-xl font-bold">
                                {{ $formatMs($latency['mean_ms'] ?? null) }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Retrieval --}}
                <div
                    x-show="tab === 'retrieval'"
                    x-cloak
                    class="p-6"
                >
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                        @foreach([
                            'precision_at_k' => ['الدقة ضمن أفضل K', 'Precision@K', 'نسبة النتائج المسترجعة التي كانت ذات صلة فعليًا.'],
                            'recall_at_k' => ['الاستدعاء ضمن أفضل K', 'Recall@K', 'نسبة المقاطع المرجعية الصحيحة التي استطاع النظام استرجاعها.'],
                            'hit_rate_at_k' => ['معدل العثور', 'Hit Rate@K', 'هل نجح النظام في إظهار نتيجة صحيحة واحدة على الأقل ضمن أفضل K؟'],
                            'mrr_at_k' => ['متوسط الرتبة المتبادلة', 'MRR@K', 'يكافئ ظهور أول نتيجة صحيحة في مرتبة مبكرة.'],
                            'ndcg_at_k' => ['جودة الترتيب', 'nDCG@K', 'يقيس جودة ترتيب النتائج الصحيحة داخل قائمة الاسترجاع.'],
                        ] as $key => [$label, $technical, $help])
                            <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-semibold">
                                            {{ $label }}
                                        </p>

                                        <p class="text-xs text-gray-500">
                                            {{ $technical }}
                                        </p>
                                    </div>

                                    <span
                                        title="{{ $help }}"
                                        class="cursor-help text-sm text-gray-400"
                                    >
                                        ؟
                                    </span>
                                </div>

                                <p class="mt-5 text-3xl font-bold">
                                    {{ $formatScore($metrics[$key] ?? null) }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Answer quality --}}
                <div
                    x-show="tab === 'answer'"
                    x-cloak
                    class="space-y-5 p-6"
                >
                    <div class="rounded-xl bg-primary-50 p-4 text-sm text-primary-900 dark:bg-primary-950/30 dark:text-primary-200">
                        هذه المقاييس تخص <strong>جودة الإجابة الناتجة</strong>
                        ويتم حسابها في مرحلة التقييم الآلي بعد الاسترجاع والتوليد.
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        @foreach([
                            'correctness' => ['صحة الإجابة', 'Correctness', 'مدى توافق إجابة النظام مع الإجابة المرجعية.'],
                            'faithfulness' => ['الالتزام بالمصادر', 'Faithfulness', 'مدى استناد الإجابة إلى السياق المسترجع دون معلومات غير مدعومة.'],
                            'answer_relevance' => ['ارتباط الإجابة بالسؤال', 'Answer Relevance', 'مدى تركيز الإجابة على السؤال المطلوب.'],
                            'abstention_accuracy' => ['دقة الامتناع عن الإجابة', 'Abstention Accuracy', 'قدرة النظام على الامتناع بشكل صحيح عندما لا تدعم الوثائق إجابة موثوقة.'],
                        ] as $key => [$label, $technical, $help])
                            <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-semibold">
                                            {{ $label }}
                                        </p>

                                        <p class="text-xs text-gray-500">
                                            {{ $technical }}
                                        </p>
                                    </div>

                                    <span
                                        title="{{ $help }}"
                                        class="cursor-help text-sm text-gray-400"
                                    >
                                        ؟
                                    </span>
                                </div>

                                <p class="mt-5 text-3xl font-bold">
                                    {{ $formatScore($metrics[$key] ?? null) }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Comparison --}}
                <div
                    x-show="tab === 'comparison'"
                    x-cloak
                    class="p-6"
                >
                    @if($pipelineRuns->isEmpty())
                        <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                            <p class="font-semibold">
                                لا توجد تشغيلات متوافقة للمقارنة حتى الآن
                            </p>

                            <p class="mt-1 text-sm text-gray-500">
                                شغّل نفس مجموعة الاختبار والوثائق وقيمة K باستخدام أكثر من مسار استرجاع.
                            </p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-right text-xs text-gray-500 dark:border-gray-800">
                                        <th class="px-3 py-3">المسار</th>
                                        <th class="px-3 py-3">Recall@K</th>
                                        <th class="px-3 py-3">MRR@K</th>
                                        <th class="px-3 py-3">nDCG@K</th>
                                        <th class="px-3 py-3">صحة الإجابة</th>
                                        <th class="px-3 py-3">الالتزام بالمصادر</th>
                                        <th class="px-3 py-3">ارتباط الإجابة</th>
                                        <th class="px-3 py-3">الزمن الكلي</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach($pipelineRuns as $candidate)
                                        <tr class="border-b border-gray-100 dark:border-gray-800/70">
                                            <td class="px-3 py-4 font-semibold">
                                                {{ \App\Filament\Pages\EvaluationDashboard::pipelineLabel(
                                                    $candidate->config_snapshot['pipeline'] ?? null
                                                ) }}
                                            </td>

                                            @foreach([
                                                'recall_at_k',
                                                'mrr_at_k',
                                                'ndcg_at_k',
                                                'correctness',
                                                'faithfulness',
                                                'answer_relevance',
                                            ] as $metric)
                                                <td class="px-3 py-4">
                                                    {{ $formatScore($candidate->metrics[$metric] ?? null) }}
                                                </td>
                                            @endforeach

                                            <td class="px-3 py-4">
                                                {{ $formatMs($candidate->latency_summary['mean_ms'] ?? null) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- Questions --}}
                <div
                    x-show="tab === 'questions'"
                    x-cloak
                    class="p-6"
                >
                    @if($questions->isEmpty())
                        <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                            لا توجد نتائج أسئلة محفوظة لهذا التشغيل.
                        </div>
                    @else
                        <div class="mb-4 flex items-center justify-between gap-4">
                            <div>
                                <h3 class="font-bold">
                                    نتائج الأسئلة
                                </h3>

                                <p class="text-sm text-gray-500">
                                    ملخص سريع لأول 20 سؤالًا. التفاصيل الكاملة متاحة من صفحة نتائج التشغيل.
                                </p>
                            </div>

                            <a
                                href="{{ \App\Filament\Pages\EvaluationResults::getUrl(['run' => $evaluation->id]) }}"
                                class="text-sm font-semibold text-primary-600"
                            >
                                التفاصيل
                            </a>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 text-right text-xs text-gray-500 dark:border-gray-800">
                                        <th class="px-3 py-3">#</th>
                                        <th class="px-3 py-3">السؤال</th>
                                        <th class="px-3 py-3">القسم</th>
                                        <th class="px-3 py-3">الحالة</th>
                                        <th class="px-3 py-3">Recall</th>
                                        <th class="px-3 py-3">MRR</th>
                                        <th class="px-3 py-3">الصحة</th>
                                        <th class="px-3 py-3">الالتزام</th>
                                        <th class="px-3 py-3">الارتباط</th>
                                        <th class="px-3 py-3">الزمن</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach($questions as $question)
                                        <tr class="border-b border-gray-100 dark:border-gray-800/70">
                                            <td class="px-3 py-4 text-gray-500">
                                                {{ $question->sequence }}
                                            </td>

                                            <td class="max-w-md px-3 py-4 font-medium">
                                                {{ \Illuminate\Support\Str::limit(
                                                    $question->question,
                                                    90
                                                ) }}
                                            </td>

                                            <td class="px-3 py-4">
                                                {{ \App\Filament\Pages\EvaluationDashboard::splitLabel(
                                                    $question->split
                                                ) }}
                                            </td>

                                            <td class="px-3 py-4">
                                                {{ \App\Filament\Pages\EvaluationDashboard::statusLabel(
                                                    $question->status
                                                ) }}
                                            </td>

                                            <td class="px-3 py-4">
                                                {{ $formatScore($question->recall_at_k) }}
                                            </td>

                                            <td class="px-3 py-4">
                                                {{ $formatScore($question->mrr_at_k) }}
                                            </td>

                                            <td class="px-3 py-4">
                                                {{ $formatScore($question->correctness) }}
                                            </td>

                                            <td class="px-3 py-4">
                                                {{ $formatScore($question->faithfulness) }}
                                            </td>

                                            <td class="px-3 py-4">
                                                {{ $formatScore($question->answer_relevance) }}
                                            </td>

                                            <td class="px-3 py-4">
                                                {{ $formatMs($question->total_ms) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
