<x-filament-panels::page>
    <div class="space-y-6" dir="rtl">

        {{-- Navigation --}}
        <div class="flex flex-wrap justify-end gap-3 text-sm font-semibold">
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
                النتائج والسجل
            </a>
        </div>

        {{-- Intro --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="max-w-4xl">
                <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                    تقييم نظام RAG
                </h2>

                <p class="mt-2 text-sm leading-7 text-gray-600 dark:text-gray-300">
                    شغّل مجموعة أسئلة مرجعية على الوثائق المفهرسة لقياس
                    جودة الاسترجاع وجودة الإجابات والأداء، ثم احفظ النتائج
                    للمقارنة بين مسارات الاسترجاع المختلفة.
                </p>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                    <div class="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 font-bold text-primary-600 dark:bg-primary-950/30">
                        1
                    </div>

                    <h3 class="font-bold">
                        جودة الاسترجاع
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-gray-500">
                        قياس العثور على الأدلة المرجعية وترتيبها باستخدام
                        Precision@K وRecall@K وHit Rate@K وMRR@K وnDCG@K.
                    </p>
                </div>

                <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                    <div class="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 font-bold text-primary-600 dark:bg-primary-950/30">
                        2
                    </div>

                    <h3 class="font-bold">
                        جودة الإجابة
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-gray-500">
                        قياس Correctness وFaithfulness وAnswer Relevance،
                        بالإضافة إلى دقة الامتناع عن الإجابة للأسئلة غير القابلة للإجابة.
                    </p>
                </div>

                <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                    <div class="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 font-bold text-primary-600 dark:bg-primary-950/30">
                        3
                    </div>

                    <h3 class="font-bold">
                        الأداء والزمن
                    </h3>

                    <p class="mt-2 text-sm leading-6 text-gray-500">
                        قياس زمن الاسترجاع وتوليد الإجابة والتقييم الآلي
                        والزمن الكلي لكل سؤال.
                    </p>
                </div>
            </div>
        </div>

        {{-- Steps / Dataset rules --}}
        <div class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h3 class="font-bold text-gray-950 dark:text-white">
                    خطوات تشغيل التقييم
                </h3>

                <ol class="mt-5 space-y-4 text-sm">
                    @foreach([
                        ['اختيار الوثائق', 'حدد الوثائق المفهرسة التي ستشكّل مجموعة البحث لهذا التقييم.'],
                        ['اختيار المسار و K', 'اختر مسار الاسترجاع وعدد النتائج التي سيعيدها النظام لكل سؤال.'],
                        ['رفع Golden Dataset', 'ارفع ملف Excel بصيغة xlsx يحتوي الأسئلة والإجابات والأدلة المرجعية.'],
                        ['بدء التقييم', 'سيعالج النظام الأسئلة في الخلفية ويحفظ النتائج التفصيلية لكل سؤال.'],
                    ] as $index => [$title, $description])
                        <li class="flex gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                {{ $index + 1 }}
                            </span>

                            <div>
                                <p class="font-semibold">
                                    {{ $title }}
                                </p>

                                <p class="mt-1 leading-6 text-gray-500">
                                    {{ $description }}
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>

            <div class="rounded-2xl border border-primary-200 bg-primary-50/60 p-6 shadow-sm dark:border-primary-800 dark:bg-primary-950/20">
                <h3 class="font-bold text-gray-950 dark:text-white">
                    ماذا يجب أن يحتوي Golden Dataset؟
                </h3>

                <div class="mt-4 space-y-3 text-sm leading-6 text-gray-700 dark:text-gray-300">
                    <p>
                        لكل سؤال أضف
                        <strong>معرفاً فريداً</strong>،
                        <strong>السؤال</strong>،
                        وحدد هل هو
                        <strong>قابل للإجابة من الوثائق</strong>.
                        ويمكن إضافة التصنيف عند الحاجة.
                    </p>

                    <p>
                        للأسئلة القابلة للإجابة، أضف
                        <strong>الإجابة المرجعية</strong>
                        ونص
                        <strong>الدليل المرجعي Golden Evidence</strong>.
                        ويمكن إضافة المصدر والصفحة والقسم عند توفرها.
                    </p>

                    <div class="rounded-xl border border-primary-200 bg-white/70 p-4 dark:border-primary-800 dark:bg-gray-900/60">
                        <p class="font-semibold text-primary-700 dark:text-primary-300">
                            لا تضع document_id أو chunk_index داخل الملف.
                        </p>

                        <p class="mt-1 text-gray-600 dark:text-gray-300">
                            يتم اختيار الوثائق من هذه الصفحة، ويقوم النظام
                            بربط الأدلة المرجعية بالمقاطع المفهرسة تلقائيًا
                            وبشكل مستقل لكل تشغيل.
                        </p>
                    </div>

                    <p class="text-xs text-gray-500">
                        للحصول على مقارنة عادلة بين المسارات، استخدم نفس
                        Golden Dataset ونفس الوثائق ونفس قيمة K.
                    </p>
                </div>
            </div>
        </div>

        {{-- Templates --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h3 class="font-bold text-gray-950 dark:text-white">
                        قوالب مجموعة الاختبار
                    </h3>

                    <p class="mt-1 text-sm text-gray-500">
                        استخدم قالب Excel كبداية. تحتوي ورقة Instructions على توضيح الحقول المطلوبة والمشروطة والاختيارية.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <x-filament::button
                        wire:click="downloadExcelTemplate"
                        color="primary"
                    >
                        تنزيل قالب Excel
                    </x-filament::button>
                </div>
            </div>
        </div>

        {{-- Evaluation form --}}
        <form
            wire:submit="submit"
            novalidate
            class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900"
        >
            <div class="mb-6">
                <h3 class="font-bold text-gray-950 dark:text-white">
                    إعداد التقييم
                </h3>

                <p class="mt-1 text-sm text-gray-500">
                    حدد الوثائق والإعدادات ثم ارفع مجموعة الاختبار المرجعية.
                </p>
            </div>

            <div class="space-y-6">
                {{ $this->form }}

                @error('data.dataset')
                    <div
                        role="alert"
                        class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-800 dark:bg-danger-950/30 dark:text-danger-300"
                    >
                        {{ $message }}
                    </div>
                @enderror

                <div class="flex justify-end border-t border-gray-200 pt-5 dark:border-gray-800">
                    <x-filament::button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="submit,data.dataset"
                    >
                        <span
                            wire:loading.remove
                            wire:target="submit"
                        >
                            بدء التقييم
                        </span>

                        <span
                            wire:loading
                            wire:target="submit"
                        >
                            جارٍ بدء التقييم...
                        </span>
                    </x-filament::button>
                </div>
            </div>
        </form>
    </div>
</x-filament-panels::page>
