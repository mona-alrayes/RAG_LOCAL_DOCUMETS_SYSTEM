<x-filament-panels::page>
    @php
        $metrics = $evaluation->metrics ?? [];
        $latency = $evaluation->latency_summary ?? [];

        $formatScore = static fn ($value) =>
            $value !== null
                ? number_format((float) $value, 2)
                : '—';

        $formatDetailedScore = static fn ($value) =>
            $value !== null
                ? number_format((float) $value, 4)
                : '—';

        $formatMs = static fn ($value) =>
            $value !== null
                ? number_format((float) $value, 0).' ms'
                : '—';

        $statusLabel = static fn (?string $status) => match ($status) {
            'queued' => 'بانتظار التنفيذ',
            'running' => 'قيد التنفيذ',
            'completed' => 'مكتمل',
            'failed' => 'فشل',
            'binding_failed' => 'فشل ربط الدليل المرجعي',
            default => $status ?: 'غير محدد',
        };

        $questionStatusLabel = static fn (?string $status) => match ($status) {
            'completed' => 'مكتمل',
            'failed' => 'فشل',
            'binding_failed' => 'فشل ربط الدليل',
            default => $status ?: 'غير محدد',
        };

        $splitLabel = static fn (?string $split) => match ($split) {
            'development' => 'تطوير',
            'held_out' => 'اختبار محجوز',
            default => $split ?: 'غير محدد',
        };

        $pipelineLabel = static fn (?string $pipeline) => match ($pipeline) {
            'dense_only' => 'بحث كثيف فقط',
            'dense_sparse_rrf' => 'كثيف + متناثر + دمج RRF',
            'dense_sparse_rrf_reranker' => 'كثيف + متناثر + RRF + إعادة ترتيب',
            default => $pipeline ?: 'غير محدد',
        };

        $statusClass = match ($evaluation->status) {
            'completed' =>
                'bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300',
            'running' =>
                'bg-info-100 text-info-700 dark:bg-info-900/40 dark:text-info-300',
            'failed' =>
                'bg-danger-100 text-danger-700 dark:bg-danger-900/40 dark:text-danger-300',
            default =>
                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };

        $kpis = [
            [
                'label' => 'الاستدعاء',
                'technical' => 'Recall@K',
                'value' => $metrics['recall_at_k'] ?? null,
                'help' => 'نسبة الأدلة المرجعية الصحيحة التي استطاع النظام استرجاعها ضمن أفضل K نتائج.',
            ],
            [
                'label' => 'جودة الترتيب',
                'technical' => 'nDCG@K',
                'value' => $metrics['ndcg_at_k'] ?? null,
                'help' => 'يقيس جودة ترتيب النتائج الصحيحة، بحيث تكون النتيجة أفضل عندما تظهر الأدلة المهمة في المراتب الأولى.',
            ],
            [
                'label' => 'صحة الإجابة',
                'technical' => 'Correctness',
                'value' => $metrics['correctness'] ?? null,
                'help' => 'يقارن إجابة النظام بالإجابة المرجعية لمعرفة مدى صحة الإجابة الناتجة.',
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
                'help' => 'يقيس مدى تركيز الإجابة على السؤال المطلوب.',
            ],
            [
                'label' => 'متوسط الزمن الكلي',
                'technical' => 'Total latency',
                'value' => null,
                'display' => $formatMs($latency['mean_ms'] ?? null),
                'help' => 'متوسط الزمن اللازم لمعالجة سؤال تقييم واحد من بداية الاسترجاع حتى انتهاء التقييم الآلي.',
            ],
        ];
    @endphp

    <div
        dir="rtl"
        class="space-y-6"
        @if(in_array($evaluation->status, ['queued', 'running'], true))
            wire:poll.5s
        @endif
        x-data="{ tab: 'overview' }"
    >
        {{-- Header --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 class="text-2xl font-bold text-gray-950 dark:text-white">
                            {{ $evaluation->name }}
                        </h2>

                        <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusClass }}">
                            {{ $statusLabel($evaluation->status) }}
                        </span>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-500">
                        <span>
                            المسار:
                            <strong class="text-gray-700 dark:text-gray-200">
                                {{ $pipelineLabel($evaluation->config_snapshot['pipeline'] ?? null) }}
                            </strong>
                        </span>

                        <span>
                            K:
                            <strong class="text-gray-700 dark:text-gray-200">
                                {{ $evaluation->k }}
                            </strong>
                        </span>

                        <span>
                            مجموعة الاختبار:
                            <strong class="text-gray-700 dark:text-gray-200">
                                {{ $evaluation->dataset_version }}
                            </strong>
                        </span>

                        <span>
                            الأسئلة:
                            <strong class="text-gray-700 dark:text-gray-200">
                                {{ $evaluation->completed_questions }}
                                /
                                {{ $evaluation->questions_count }}
                            </strong>
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3 text-sm font-semibold">
                    <a
                        href="{{ \App\Filament\Pages\EvaluationDashboard::getUrl() }}"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        لوحة تقييم النظام
                    </a>

                    <a
                        href="{{ \App\Filament\Resources\EvaluationRunResource::getUrl() }}"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        سجل التقييمات
                    </a>
                </div>
            </div>

            @if($evaluation->status === 'queued')
                <div class="mt-5 rounded-xl bg-info-50 p-4 text-sm text-info-800 dark:bg-info-950/30 dark:text-info-200">
                    التقييم بانتظار عامل المهام الخلفية، وستتحدث هذه الصفحة تلقائيًا عند بدء التنفيذ.
                </div>
            @endif

            @if($evaluation->status === 'running')
                @php
                    $progressPercentage = $evaluation->questions_count > 0
                        ? min(
                            100,
                            (int) round(
                                ($evaluation->completed_questions / $evaluation->questions_count) * 100
                            )
                        )
                        : 0;
                @endphp

                <div class="mt-5 rounded-xl border border-info-200 bg-info-50 p-4 text-info-800 dark:border-info-800 dark:bg-info-950/30 dark:text-info-200">
                    <div class="flex items-center justify-between gap-4 text-sm font-semibold">
                        <span>جارٍ تقييم الأسئلة...</span>
                        <span>{{ $progressPercentage }}%</span>
                    </div>

                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-info-100 dark:bg-info-950">
                        <div
                            class="h-full rounded-full bg-info-600 transition-all duration-500"
                            style="width: {{ $progressPercentage }}%"
                        ></div>
                    </div>

                    <p class="mt-3 text-sm">
                        تم تقييم
                        <strong>{{ $evaluation->completed_questions }}</strong>
                        من
                        <strong>{{ $evaluation->questions_count }}</strong>
                        سؤال.
                        يتم تحديث الصفحة تلقائيًا.
                    </p>
                </div>
            @endif

            @if($evaluation->status === 'completed')
                <div class="mt-5 rounded-xl border border-success-200 bg-success-50 p-4 text-sm text-success-800 dark:border-success-800 dark:bg-success-950/30 dark:text-success-200">
                    <strong>اكتمل التقييم بنجاح.</strong>
                    تمت معالجة
                    {{ $evaluation->completed_questions }}
                    من
                    {{ $evaluation->questions_count }}
                    سؤال، والنتائج النهائية جاهزة أدناه.
                </div>
            @endif

            @if($evaluation->status === 'failed' && ! $evaluation->error_code)
                <div
                    role="alert"
                    class="mt-5 rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-800 dark:bg-danger-950/30 dark:text-danger-200"
                >
                    <strong>فشل التقييم.</strong>
                    تعذر إكمال التشغيل. راجع سجل التطبيق لمعرفة التفاصيل التقنية.
                </div>
            @endif

            @if($evaluation->error_code)
                <div
                    role="alert"
                    class="mt-5 rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-800 dark:bg-danger-950/30 dark:text-danger-200"
                >
                    <strong>توقف التقييم.</strong>
                    رمز الخطأ:
                    <code>{{ $evaluation->error_code }}</code>.
                    لم تُنشر مقاييس نهائية لهذا التشغيل.
                </div>
            @endif
        </div>

        {{-- KPI --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
            @foreach($kpis as $kpi)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-2">
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

                    <p class="mt-5 text-3xl font-bold text-gray-950 dark:text-white">
                        {{ $kpi['display'] ?? $formatScore($kpi['value']) }}
                    </p>
                </div>
            @endforeach
        </div>

        {{-- Main tabs --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="border-b border-gray-200 px-3 dark:border-gray-800">
                <div class="flex gap-1 overflow-x-auto">
                    @foreach([
                        'overview' => 'ملخص التشغيل',
                        'retrieval' => 'جودة الاسترجاع',
                        'answer' => 'جودة الإجابة',
                        'performance' => 'الأداء',
                        'comparison' => 'المقارنة',
                        'questions' => 'نتائج الأسئلة',
                        'config' => 'إعدادات التشغيل',
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
                        'successful_questions' => 'الأسئلة الناجحة',
                        'failed_questions' => 'الأسئلة الفاشلة',
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

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                        <h3 class="font-bold text-gray-950 dark:text-white">
                            معلومات التشغيل
                        </h3>

                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">بدأ التنفيذ</dt>
                                <dd class="font-medium">
                                    {{ $evaluation->started_at ?? 'لم يبدأ بعد' }}
                                </dd>
                            </div>

                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">انتهى التنفيذ</dt>
                                <dd class="font-medium">
                                    {{ $evaluation->completed_at ?? '—' }}
                                </dd>
                            </div>

                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">إصدار مجموعة الاختبار</dt>
                                <dd class="font-medium">
                                    {{ $evaluation->dataset_version }}
                                </dd>
                            </div>

                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">عدد النتائج K</dt>
                                <dd class="font-medium">
                                    {{ $evaluation->k }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                        <h3 class="font-bold text-gray-950 dark:text-white">
                            كيف تُقرأ النتائج؟
                        </h3>

                        <div class="mt-4 space-y-3 text-sm leading-6 text-gray-600 dark:text-gray-300">
                            <p>
                                <strong>جودة الاسترجاع</strong>
                                تقيس قدرة النظام على العثور على الأدلة المرجعية وترتيبها.
                            </p>

                            <p>
                                <strong>جودة الإجابة</strong>
                                تقيس صحة إجابة النموذج ومدى التزامها بالسياق المسترجع وارتباطها بالسؤال.
                            </p>

                            <p>
                                انخفاض Precision@K وحدها لا يعني بالضرورة ضعف الاسترجاع،
                                خصوصًا عندما يكون عدد الأدلة المرجعية أقل من K.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Retrieval quality --}}
            <div
                x-show="tab === 'retrieval'"
                x-cloak
                class="space-y-5 p-6"
            >
                <div class="rounded-xl bg-primary-50 p-4 text-sm leading-6 text-primary-900 dark:bg-primary-950/30 dark:text-primary-200">
                    تقيس هذه المجموعة جودة مرحلة الاسترجاع فقط.
                    تتم مقارنة المقاطع المسترجعة بالأدلة المرجعية المرتبطة بالسؤال.
                </div>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    @foreach([
                        'precision_at_k' => [
                            'الدقة ضمن أفضل K',
                            'Precision@K',
                            'نسبة النتائج المسترجعة التي كانت مرجعية فعلًا. قد تنخفض طبيعيًا عندما يكون عدد الأدلة المرجعية أقل من K.',
                        ],
                        'recall_at_k' => [
                            'الاستدعاء ضمن أفضل K',
                            'Recall@K',
                            'نسبة جميع الأدلة المرجعية التي نجح النظام في العثور عليها.',
                        ],
                        'hit_rate_at_k' => [
                            'معدل العثور',
                            'Hit Rate@K',
                            'هل ظهر دليل مرجعي صحيح واحد على الأقل ضمن أفضل K؟',
                        ],
                        'mrr_at_k' => [
                            'متوسط الرتبة المتبادلة',
                            'MRR@K',
                            'يكافئ النظام عندما يظهر أول دليل صحيح في مرتبة مبكرة.',
                        ],
                        'ndcg_at_k' => [
                            'جودة الترتيب',
                            'nDCG@K',
                            'يقيس مدى جودة ترتيب الأدلة الصحيحة داخل قائمة النتائج.',
                        ],
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
                                    class="cursor-help text-sm font-bold text-gray-400"
                                >
                                    ؟
                                </span>
                            </div>

                            <p class="mt-5 text-3xl font-bold">
                                {{ $formatDetailedScore($metrics[$key] ?? null) }}
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
                <div class="rounded-xl bg-primary-50 p-4 text-sm leading-6 text-primary-900 dark:bg-primary-950/30 dark:text-primary-200">
                    هذه المقاييس تخص الإجابة المولدة، ويتم حسابها في مرحلة
                    <strong>التقييم الآلي Judge</strong>
                    بعد اكتمال الاسترجاع وتوليد الإجابة.
                </div>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach([
                        'correctness' => [
                            'صحة الإجابة',
                            'Correctness',
                            'مدى توافق إجابة النظام مع الإجابة المرجعية.',
                        ],
                        'faithfulness' => [
                            'الالتزام بالمصادر',
                            'Faithfulness',
                            'مدى اعتماد الإجابة على السياق المسترجع دون إضافة معلومات غير مدعومة.',
                        ],
                        'answer_relevance' => [
                            'ارتباط الإجابة بالسؤال',
                            'Answer Relevance',
                            'مدى تركيز الإجابة على المطلوب في السؤال.',
                        ],
                        'abstention_accuracy' => [
                            'دقة الامتناع عن الإجابة',
                            'Abstention Accuracy',
                            'قدرة النظام على الامتناع بشكل صحيح عندما لا تحتوي الوثائق على إجابة مدعومة.',
                        ],
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
                                    class="cursor-help text-sm font-bold text-gray-400"
                                >
                                    ؟
                                </span>
                            </div>

                            <p class="mt-5 text-3xl font-bold">
                                {{ $formatDetailedScore($metrics[$key] ?? null) }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Performance --}}
            <div
                x-show="tab === 'performance'"
                x-cloak
                class="space-y-5 p-6"
            >
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    @foreach([
                        'retrieval_mean_ms' => ['متوسط زمن الاسترجاع', 'الوقت المستهلك في البحث عن المقاطع المناسبة.'],
                        'generation_mean_ms' => ['متوسط زمن توليد الإجابة', 'الوقت الذي يحتاجه نموذج اللغة لإنتاج الإجابة.'],
                        'judge_mean_ms' => ['متوسط زمن التقييم الآلي', 'الوقت المستهلك في حساب مقاييس جودة الإجابة.'],
                        'mean_ms' => ['متوسط الزمن الكلي', 'متوسط الزمن الكامل لكل سؤال.'],
                        'p95_ms' => ['الزمن الكلي عند P95', '95% من الأسئلة انتهت خلال هذه المدة أو أقل.'],
                    ] as $key => [$label, $help])
                        <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                            <div class="flex items-start justify-between gap-2">
                                <p class="font-semibold">
                                    {{ $label }}
                                </p>

                                <span
                                    title="{{ $help }}"
                                    class="cursor-help text-sm font-bold text-gray-400"
                                >
                                    ؟
                                </span>
                            </div>

                            <p class="mt-5 text-2xl font-bold">
                                {{ $formatMs($latency[$key] ?? null) }}
                            </p>
                        </div>
                    @endforeach
                </div>

                <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm leading-6 text-warning-900 dark:border-warning-800 dark:bg-warning-950/30 dark:text-warning-200">
                    أزمنة التنفيذ تتأثر بمواصفات الجهاز، حالة تحميل النماذج،
                    الذاكرة المؤقتة وحمل مزود الخدمة؛ لذلك تُستخدم للمقارنة الوصفية
                    عند ثبات بيئة الاختبار قدر الإمكان.
                </div>
            </div>

            {{-- Historical comparison --}}
            <div
                x-show="tab === 'comparison'"
                x-cloak
                class="space-y-5 p-6"
            >
                <div>
                    <label
                        for="evaluation-baseline"
                        class="mb-2 block text-sm font-semibold"
                    >
                        تشغيل المقارنة المرجعي
                    </label>

                    <select
                        id="evaluation-baseline"
                        wire:model.live="baselineId"
                        class="w-full rounded-xl border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-950"
                    >
                        <option value="">
                            اختر تشغيلًا للمقارنة
                        </option>

                        @foreach($compatibleRuns as $candidate)
                            <option value="{{ $candidate->id }}">
                                #{{ $candidate->id }}
                                — {{ $candidate->name }}
                                — {{ $pipelineLabel($candidate->config_snapshot['pipeline'] ?? null) }}
                            </option>
                        @endforeach
                    </select>

                    @error('baselineId')
                        <p
                            role="alert"
                            class="mt-2 text-sm text-danger-600"
                        >
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                @if($compatibleRuns->isEmpty())
                    <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                        <p class="font-semibold">
                            لا توجد تشغيلات مكتملة ومتوافقة للمقارنة حتى الآن.
                        </p>

                        <p class="mt-2 text-sm text-gray-500">
                            يجب أن تستخدم التشغيلات نفس مجموعة الاختبار وK والوثائق وإصدار المقاييس.
                        </p>
                    </div>
                @endif

                @if($baseline)
                    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-950">
                                <tr class="text-right text-xs text-gray-500">
                                    <th class="px-4 py-3">المقياس</th>
                                    <th class="px-4 py-3">المرجعي</th>
                                    <th class="px-4 py-3">الحالي</th>
                                    <th class="px-4 py-3">الفرق</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach([
                                    'precision_at_k' => 'Precision@K',
                                    'recall_at_k' => 'Recall@K',
                                    'hit_rate_at_k' => 'Hit Rate@K',
                                    'mrr_at_k' => 'MRR@K',
                                    'ndcg_at_k' => 'nDCG@K',
                                    'correctness' => 'صحة الإجابة',
                                    'faithfulness' => 'الالتزام بالمصادر',
                                    'answer_relevance' => 'ارتباط الإجابة',
                                ] as $key => $label)
                                    @php
                                        $previous = $baseline->metrics[$key] ?? null;
                                        $current = $evaluation->metrics[$key] ?? null;
                                        $delta = (
                                            $previous !== null
                                            && $current !== null
                                        )
                                            ? $current - $previous
                                            : null;
                                    @endphp

                                    <tr class="border-t border-gray-100 dark:border-gray-800">
                                        <th class="px-4 py-3 text-right font-semibold">
                                            {{ $label }}
                                        </th>

                                        <td class="px-4 py-3">
                                            {{ $formatDetailedScore($previous) }}
                                        </td>

                                        <td class="px-4 py-3">
                                            {{ $formatDetailedScore($current) }}
                                        </td>

                                        <td class="px-4 py-3 font-semibold">
                                            @if($delta !== null)
                                                <span class="{{ $delta > 0 ? 'text-success-600' : ($delta < 0 ? 'text-danger-600' : 'text-gray-500') }}">
                                                    {{ sprintf('%+.4f', $delta) }}
                                                </span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if(!empty($configurationDifferences))
                        <details class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                            <summary class="cursor-pointer font-semibold">
                                الفروقات في إعدادات التشغيل
                            </summary>

                            <div class="mt-4 space-y-3 text-sm">
                                @foreach($configurationDifferences as $key => $values)
                                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-950">
                                        <p class="font-semibold">
                                            {{ $key }}
                                        </p>

                                        <p class="mt-1 text-gray-500">
                                            المرجعي:
                                            {{ json_encode(
                                                $values['baseline'],
                                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                            ) }}
                                        </p>

                                        <p class="text-gray-500">
                                            الحالي:
                                            {{ json_encode(
                                                $values['current'],
                                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                            ) }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @endif
            </div>

            {{-- Questions --}}
            <div
                x-show="tab === 'questions'"
                x-cloak
                class="space-y-5 p-6"
            >
                {{-- Filters --}}
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-500">
                            الحالة
                        </label>

                        <select
                            wire:model.live="questionStatus"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"
                        >
                            <option value="all">الكل</option>
                            <option value="completed">مكتمل</option>
                            <option value="failed">فشل</option>
                            <option value="binding_failed">فشل ربط الدليل</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-500">
                            القسم
                        </label>

                        <select
                            wire:model.live="questionSplit"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"
                        >
                            <option value="all">الكل</option>
                            <option value="development">تطوير</option>
                            <option value="held_out">اختبار محجوز</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-500">
                            قابلية الإجابة
                        </label>

                        <select
                            wire:model.live="questionAnswerability"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"
                        >
                            <option value="all">الكل</option>
                            <option value="answerable">قابل للإجابة</option>
                            <option value="unanswerable">غير قابل للإجابة</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-500">
                            التصنيف
                        </label>

                        <select
                            wire:model.live="questionCategory"
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"
                        >
                            <option value="all">الكل</option>

                            @foreach($categories as $category)
                                <option value="{{ $category }}">
                                    {{ $category }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Compact questions table --}}
                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-800">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-950">
                            <tr class="text-right text-xs text-gray-500">
                                <th class="px-4 py-3">#</th>
                                <th class="px-4 py-3">السؤال</th>
                                <th class="px-4 py-3">الحالة</th>
                                <th class="px-4 py-3">Recall</th>
                                <th class="px-4 py-3">MRR</th>
                                <th class="px-4 py-3">الصحة</th>
                                <th class="px-4 py-3">الالتزام</th>
                                <th class="px-4 py-3">الارتباط</th>
                                <th class="px-4 py-3">الزمن</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($questions as $question)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="px-4 py-3 text-gray-500">
                                        {{ $question->sequence }}
                                    </td>

                                    <td class="max-w-md px-4 py-3">
                                        <details>
                                            <summary class="cursor-pointer font-semibold text-gray-900 hover:text-primary-600 dark:text-white">
                                                {{ \Illuminate\Support\Str::limit(
                                                    $question->question,
                                                    100
                                                ) }}
                                            </summary>

                                            <div class="mt-5 space-y-5 rounded-xl bg-gray-50 p-5 dark:bg-gray-950">
                                                {{-- Core answers --}}
                                                <div class="grid gap-4 xl:grid-cols-2">
                                                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                                        <p class="text-xs font-semibold text-gray-500">
                                                            السؤال
                                                        </p>

                                                        <p class="mt-2 leading-7">
                                                            {{ $question->question }}
                                                        </p>
                                                    </div>

                                                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                                        <p class="text-xs font-semibold text-gray-500">
                                                            قابلية الإجابة
                                                        </p>

                                                        <p class="mt-2 font-semibold">
                                                            {{ $question->is_answerable
                                                                ? 'قابل للإجابة من الوثائق'
                                                                : 'غير قابل للإجابة من الوثائق' }}
                                                        </p>
                                                    </div>
                                                </div>

                                                @if($question->reference_answer)
                                                    <div class="rounded-xl border border-primary-200 bg-primary-50 p-4 dark:border-primary-800 dark:bg-primary-950/20">
                                                        <p class="text-xs font-semibold text-primary-700 dark:text-primary-300">
                                                            الإجابة المرجعية
                                                        </p>

                                                        <p class="mt-2 leading-7">
                                                            {{ $question->reference_answer }}
                                                        </p>
                                                    </div>
                                                @endif

                                                @if($question->generated_answer)
                                                    <div class="rounded-xl border border-success-200 bg-success-50 p-4 dark:border-success-800 dark:bg-success-950/20">
                                                        <p class="text-xs font-semibold text-success-700 dark:text-success-300">
                                                            إجابة النظام
                                                        </p>

                                                        <p class="mt-2 leading-7">
                                                            {{ $question->generated_answer }}
                                                        </p>
                                                    </div>
                                                @endif

                                                {{-- Per-question metrics --}}
                                                <div>
                                                    <p class="mb-3 text-sm font-bold">
                                                        مقاييس هذا السؤال
                                                    </p>

                                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                                        @foreach([
                                                            'recall_at_k' => 'Recall@K',
                                                            'mrr_at_k' => 'MRR@K',
                                                            'ndcg_at_k' => 'nDCG@K',
                                                            'correctness' => 'الصحة',
                                                            'faithfulness' => 'الالتزام',
                                                        ] as $field => $label)
                                                            <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-gray-900">
                                                                <p class="text-xs text-gray-500">
                                                                    {{ $label }}
                                                                </p>

                                                                <p class="mt-1 text-lg font-bold">
                                                                    {{ $formatDetailedScore($question->{$field}) }}
                                                                </p>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>

                                                {{-- Golden evidence --}}
                                                @if(!empty($question->golden_evidence))
                                                    <div>
                                                        <p class="mb-3 text-sm font-bold">
                                                            الأدلة المرجعية
                                                        </p>

                                                        <div class="space-y-3">
                                                            @foreach($question->golden_evidence as $evidence)
                                                                <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-950/20">
                                                                    <div class="mb-2 flex flex-wrap gap-3 text-xs text-gray-500">
                                                                        @if(!empty($evidence['source']))
                                                                            <span>
                                                                                المصدر:
                                                                                {{ $evidence['source'] }}
                                                                            </span>
                                                                        @endif

                                                                        @if(!empty($evidence['page']))
                                                                            <span>
                                                                                الصفحة:
                                                                                {{ $evidence['page'] }}
                                                                            </span>
                                                                        @endif

                                                                        @if(!empty($evidence['section']))
                                                                            <span>
                                                                                القسم:
                                                                                {{ $evidence['section'] }}
                                                                            </span>
                                                                        @endif
                                                                    </div>

                                                                    <p class="leading-7">
                                                                        {{ $evidence['evidence_text'] ?? '—' }}
                                                                    </p>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- Bound chunks --}}
                                                @if(!empty($question->relevant_chunks))
                                                    <div>
                                                        <p class="mb-3 text-sm font-bold">
                                                            المقاطع المرجعية المرتبطة
                                                        </p>

                                                        <div class="grid gap-3 md:grid-cols-2">
                                                            @foreach($question->relevant_chunks as $chunk)
                                                                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                                                    <div class="flex flex-wrap gap-3 text-xs text-gray-500">
                                                                        @if(isset($chunk['document_id']))
                                                                            <span>
                                                                                الوثيقة #{{ $chunk['document_id'] }}
                                                                            </span>
                                                                        @endif

                                                                        @if(isset($chunk['chunk_index']))
                                                                            <span>
                                                                                المقطع #{{ $chunk['chunk_index'] }}
                                                                            </span>
                                                                        @endif

                                                                        @if(isset($chunk['page']))
                                                                            <span>
                                                                                الصفحة {{ $chunk['page'] }}
                                                                            </span>
                                                                        @endif
                                                                    </div>

                                                                    @php
                                                                        $chunkText =
                                                                            $chunk['text']
                                                                            ?? $chunk['content']
                                                                            ?? $chunk['chunk_text']
                                                                            ?? null;
                                                                    @endphp

                                                                    @if($chunkText)
                                                                        <p class="mt-3 leading-7">
                                                                            {{ $chunkText }}
                                                                        </p>
                                                                    @endif
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- Retrieved context --}}
                                                @if(!empty($question->retrieved_context))
                                                    <div>
                                                        <p class="mb-3 text-sm font-bold">
                                                            السياق المسترجع الذي وصل إلى نموذج الإجابة
                                                        </p>

                                                        <div class="space-y-3">
                                                            @foreach($question->retrieved_context as $rank => $chunk)
                                                                @php
                                                                    $retrievedText =
                                                                        $chunk['text']
                                                                        ?? $chunk['content']
                                                                        ?? $chunk['chunk_text']
                                                                        ?? null;
                                                                @endphp

                                                                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                                                    <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500">
                                                                        <span class="font-bold text-primary-600">
                                                                            النتيجة {{ $rank + 1 }}
                                                                        </span>

                                                                        @if(isset($chunk['document_id']))
                                                                            <span>
                                                                                الوثيقة #{{ $chunk['document_id'] }}
                                                                            </span>
                                                                        @endif

                                                                        @if(isset($chunk['chunk_index']))
                                                                            <span>
                                                                                المقطع #{{ $chunk['chunk_index'] }}
                                                                            </span>
                                                                        @endif

                                                                        @if(isset($chunk['reranker_score']) && $chunk['reranker_score'] !== null)
                                                                            <span>
                                                                                درجة إعادة الترتيب:
                                                                                {{ number_format((float) $chunk['reranker_score'], 4) }}
                                                                            </span>
                                                                        @endif
                                                                    </div>

                                                                    @if($retrievedText)
                                                                        <p class="mt-3 leading-7">
                                                                            {{ $retrievedText }}
                                                                        </p>
                                                                    @endif
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif

                                                {{-- Judge --}}
                                                <div class="grid gap-4 md:grid-cols-2">
                                                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                                        <p class="text-sm font-bold">
                                                            نتيجة التقييم الآلي
                                                        </p>

                                                        <dl class="mt-3 space-y-2 text-sm">
                                                            <div class="flex justify-between gap-3">
                                                                <dt class="text-gray-500">
                                                                    صحة الإجابة
                                                                </dt>
                                                                <dd class="font-semibold">
                                                                    {{ $formatDetailedScore($question->correctness) }}
                                                                </dd>
                                                            </div>

                                                            <div class="flex justify-between gap-3">
                                                                <dt class="text-gray-500">
                                                                    الالتزام بالمصادر
                                                                </dt>
                                                                <dd class="font-semibold">
                                                                    {{ $formatDetailedScore($question->faithfulness) }}
                                                                </dd>
                                                            </div>

                                                            <div class="flex justify-between gap-3">
                                                                <dt class="text-gray-500">
                                                                    ارتباط الإجابة
                                                                </dt>
                                                                <dd class="font-semibold">
                                                                    {{ $formatDetailedScore($question->answer_relevance) }}
                                                                </dd>
                                                            </div>

                                                            @if(!$question->is_answerable)
                                                                <div class="flex justify-between gap-3">
                                                                    <dt class="text-gray-500">
                                                                        الامتناع الصحيح
                                                                    </dt>
                                                                    <dd class="font-semibold">
                                                                        {{ $question->abstention_correct === null
                                                                            ? '—'
                                                                            : ($question->abstention_correct ? 'نعم' : 'لا') }}
                                                                    </dd>
                                                                </div>
                                                            @endif
                                                        </dl>
                                                    </div>

                                                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                                        <p class="text-sm font-bold">
                                                            الأزمنة
                                                        </p>

                                                        <dl class="mt-3 space-y-2 text-sm">
                                                            <div class="flex justify-between gap-3">
                                                                <dt class="text-gray-500">
                                                                    الاسترجاع
                                                                </dt>
                                                                <dd>
                                                                    {{ $formatMs($question->retrieval_ms) }}
                                                                </dd>
                                                            </div>

                                                            <div class="flex justify-between gap-3">
                                                                <dt class="text-gray-500">
                                                                    التوليد
                                                                </dt>
                                                                <dd>
                                                                    {{ $formatMs($question->generation_ms) }}
                                                                </dd>
                                                            </div>

                                                            <div class="flex justify-between gap-3">
                                                                <dt class="text-gray-500">
                                                                    التقييم الآلي
                                                                </dt>
                                                                <dd>
                                                                    {{ $formatMs($question->judge_ms) }}
                                                                </dd>
                                                            </div>

                                                            <div class="flex justify-between gap-3 font-bold">
                                                                <dt>
                                                                    الزمن الكلي
                                                                </dt>
                                                                <dd>
                                                                    {{ $formatMs($question->total_ms) }}
                                                                </dd>
                                                            </div>
                                                        </dl>
                                                    </div>
                                                </div>

                                                @if($question->error_code)
                                                    <div class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm dark:border-danger-800 dark:bg-danger-950/20">
                                                        <p class="font-bold text-danger-700 dark:text-danger-300">
                                                            حدث خطأ أثناء تقييم هذا السؤال
                                                        </p>

                                                        <p class="mt-2">
                                                            المرحلة:
                                                            {{ $question->error_stage ?? 'غير محددة' }}
                                                            ·
                                                            الرمز:
                                                            {{ $question->error_code }}
                                                        </p>
                                                    </div>
                                                @endif

                                                @if(!empty($question->judge_details))
                                                    <details class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                                        <summary class="cursor-pointer text-sm font-semibold">
                                                            التفاصيل التقنية للتقييم الآلي
                                                        </summary>

                                                        <pre class="mt-4 overflow-x-auto whitespace-pre-wrap text-xs leading-6">{{ json_encode(
                                                            $question->judge_details,
                                                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                                        ) }}</pre>
                                                    </details>
                                                @endif
                                            </div>
                                        </details>
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $questionStatusLabel($question->status) }}
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $formatScore($question->recall_at_k) }}
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $formatScore($question->mrr_at_k) }}
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $formatScore($question->correctness) }}
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $formatScore($question->faithfulness) }}
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $formatScore($question->answer_relevance) }}
                                    </td>

                                    <td class="px-4 py-3">
                                        {{ $formatMs($question->total_ms) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td
                                        colspan="9"
                                        class="px-4 py-10 text-center text-gray-500"
                                    >
                                        لا توجد أسئلة مطابقة للفلاتر الحالية.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Technical config --}}
            <div
                x-show="tab === 'config'"
                x-cloak
                class="space-y-5 p-6"
            >
                <div class="rounded-xl bg-gray-50 p-4 text-sm leading-6 text-gray-600 dark:bg-gray-950 dark:text-gray-300">
                    هذا القسم مخصص للتفاصيل التقنية اللازمة لإعادة إنتاج تجربة التقييم والتحقق من ثبات الإعدادات.
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                        <p class="text-xs font-semibold text-gray-500">
                            SHA-256 لمجموعة الاختبار
                        </p>

                        <code class="mt-2 block break-all text-sm">
                            {{ $evaluation->dataset_sha256 }}
                        </code>
                    </div>

                    <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                        <p class="text-xs font-semibold text-gray-500">
                            إصدار مجموعة الاختبار
                        </p>

                        <p class="mt-2 font-semibold">
                            {{ $evaluation->dataset_version }}
                        </p>
                    </div>
                </div>

                @if(isset($evaluation->config_snapshot['metric_version']))
                    @include('filament.pages.partials-evaluation-snapshot')
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
