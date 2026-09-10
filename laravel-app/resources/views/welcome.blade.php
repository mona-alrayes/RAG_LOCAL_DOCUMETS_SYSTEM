<x-layouts.marketing title="الرئيسية">
    <div data-landing-reference="approved-dark-cyan">
        {{-- =====================================================
            HERO
        ====================================================== --}}
        <section
            id="home"
            data-landing-hero
            class="landing-section landing-hero relative overflow-hidden border-b border-white/[0.07]"
        >
            <div
                class="landing-glow landing-glow-right"
                aria-hidden="true"
            ></div>

            <div
                class="landing-container grid items-center gap-10 lg:grid-cols-[0.95fr_1.05fr] lg:gap-14"
            >
                {{-- Hero copy --}}
                <div class="relative z-10 max-w-[38rem]">
                    <p class="landing-kicker">
                        منصة عربية للاستعلام عن الوثائق
                    </p>

                    <h1 class="landing-hero-title">
                        اسأل مستنداتك

                        <span class="mt-2 block text-cyan-400">
                            واحصل على إجابات مدعومة بالمصادر
                        </span>
                    </h1>

                    <p class="landing-hero-copy">
                        ارفع ملفات PDF وDOCX وTXT، واختر الوثائق المرتبطة
                        بالمحادثة، ثم اطرح سؤالك. يسترجع النظام المقاطع
                        الأكثر ارتباطًا بالسؤال ويستخدمها لبناء الإجابة،
                        مع عرض المصادر المستخدمة للمراجعة.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a
                            href="{{ route('register') }}"
                            class="landing-primary-button"
                        >
                            ابدأ الآن

                            <svg
                                viewBox="0 0 24 24"
                                class="size-5"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M19 12H5m0 0 5-5m-5 5 5 5"
                                />
                            </svg>
                        </a>

                        <a
                            href="#features"
                            class="landing-secondary-button"
                        >
                            استكشف المميزات
                        </a>
                    </div>

                    <div
                        class="mt-7 flex flex-wrap gap-x-6 gap-y-3 text-xs text-mist-300/80"
                    >
                        <span class="landing-check">
                            PDF وDOCX وTXT
                        </span>

                        <span class="landing-check">
                            اختيار الوثائق لكل محادثة
                        </span>

                        <span class="landing-check">
                            مصادر قابلة للمراجعة
                        </span>
                    </div>
                </div>

                {{-- Scalable hero visual --}}
                <div
                    data-landing-hero-visual
                    class="landing-hero-visual"
                    aria-label="تمثيل بصري لواجهة الاستعلام عن الوثائق"
                >
                    <div class="landing-hero-aura" aria-hidden="true"></div>

                    <div class="landing-file-rail" aria-hidden="true">
                        <div class="landing-file-card landing-file-pdf">
                            <svg viewBox="0 0 24 24">
                                <path d="M7 3h7l4 4v14H7z" />
                                <path d="M14 3v5h5" />
                            </svg>
                            <span>PDF</span>
                        </div>

                        <div class="landing-file-card landing-file-docx">
                            <svg viewBox="0 0 24 24">
                                <path d="M7 3h7l4 4v14H7z" />
                                <path d="M14 3v5h5" />
                            </svg>
                            <span>DOCX</span>
                        </div>

                        <div class="landing-file-card landing-file-txt">
                            <svg viewBox="0 0 24 24">
                                <path d="M7 3h7l4 4v14H7z" />
                                <path d="M10 12h5M10 16h5" />
                            </svg>
                            <span>TXT</span>
                        </div>
                    </div>

                    <div class="landing-hero-connectors" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>

                    <div class="landing-workbench">
                        <div class="landing-workbench-topbar">
                            <div class="landing-workbench-brand">
                                <span class="landing-mini-logo">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M7 3h7l4 4v14H7z" />
                                        <path d="M14 3v5h5M10 12h5M10 16h5" />
                                    </svg>
                                </span>

                                <span>نظام الاستعلام الذكي</span>
                            </div>

                            <div class="landing-window-dots" aria-hidden="true">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                        </div>

                        <div class="landing-workbench-body">
                            <aside class="landing-demo-sidebar" aria-hidden="true">
                                <div class="landing-demo-nav landing-demo-nav-active">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M5 5h14v11H9l-4 4V5Z" />
                                    </svg>
                                    <span>المحادثات</span>
                                </div>

                                <div class="landing-demo-nav">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M7 3h7l4 4v14H7z" />
                                        <path d="M14 3v5h5" />
                                    </svg>
                                    <span>المستندات</span>
                                </div>

                                <div class="landing-demo-nav">
                                    <svg viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="3" />
                                        <path d="M12 2v3M12 19v3M4.9 4.9 7 7M17 17l2.1 2.1M2 12h3M19 12h3" />
                                    </svg>
                                    <span>الإعدادات</span>
                                </div>
                            </aside>

                            <div class="landing-demo-chat">
                                <div class="landing-demo-question">
                                    ما أبرز النقاط في هذا المستند؟
                                </div>

                                <div class="landing-demo-answer">
                                    <p>بناءً على المقاطع المسترجعة:</p>

                                    <ul>
                                        <li>أهداف المستند الرئيسية</li>
                                        <li>النقاط الأكثر ارتباطًا بالسؤال</li>
                                        <li>المعلومات المتاحة في السياق</li>
                                    </ul>
                                </div>

                                <div class="landing-demo-sources">
                                    <div class="landing-demo-source-title">
                                        المصادر المستخدمة
                                    </div>

                                    <div class="landing-demo-source-row">
                                        <span class="landing-source-icon">PDF</span>

                                        <div>
                                            <strong>دليل المشروع.pdf</strong>
                                            <small>صفحة 12 · المقطع 4</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="landing-demo-composer">
                                    <span>اكتب سؤالك هنا...</span>

                                    <span class="landing-demo-send">
                                        <svg viewBox="0 0 24 24">
                                            <path d="M12 19V5m0 0-5 5m5-5 5 5" />
                                        </svg>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- =====================================================
            FEATURES
        ====================================================== --}}
        <section
            id="features"
            data-landing-features
            class="landing-section border-b border-white/[0.07] bg-navy-900/30"
        >
            <div class="landing-container">
                <header class="landing-section-heading">
                    <p class="landing-section-label">
                        المميزات الرئيسية
                    </p>

                    <h2>
                        الأدوات الأساسية للعمل مع مستنداتك
                    </h2>

                    <p>
                        وظائف مترابطة لمعالجة المستندات، استرجاع المقاطع
                        ذات الصلة، وبناء إجابات يمكن الرجوع إلى مصادرها.
                    </p>
                </header>

                <div class="mt-12 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <article class="landing-feature-card">
                        <div class="landing-icon">
                            <svg
                                viewBox="0 0 24 24"
                                class="size-7"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                                aria-hidden="true"
                            >
                                <path d="M4 7h16M7 12h10M9 17h6" />
                                <circle cx="8" cy="7" r="1.5" />
                                <circle cx="15" cy="12" r="1.5" />
                                <circle cx="12" cy="17" r="1.5" />
                            </svg>
                        </div>

                        <h3>مسارات معالجة مرنة</h3>

                        <p>
                            يدعم النظام مسارات معالجة مختلفة بحسب
                            الإمكانات المفعّلة في بيئة التشغيل، ومنها
                            Cloud وHybrid Local.
                        </p>
                    </article>

                    <article class="landing-feature-card">
                        <div class="landing-icon">
                            <svg
                                viewBox="0 0 24 24"
                                class="size-7"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                                aria-hidden="true"
                            >
                                <circle cx="10.5" cy="10.5" r="6" />
                                <path d="m15 15 5 5" />
                            </svg>
                        </div>

                        <h3>استرجاع متعدد المراحل</h3>

                        <p>
                            يجمع مسار البحث بين الاسترجاع المتاح للنظام،
                            ودمج النتائج وإعادة ترتيبها لاختيار المقاطع
                            المرشحة للسياق.
                        </p>
                    </article>

                    <article class="landing-feature-card">
                        <div class="landing-icon">
                            <svg
                                viewBox="0 0 24 24"
                                class="size-7"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                                aria-hidden="true"
                            >
                                <path d="M7 3h7l4 4v14H7z" />
                                <path d="M14 3v5h5M10 12h5M10 16h5" />
                            </svg>
                        </div>

                        <h3>صيغ مستندات متعددة</h3>

                        <p>
                            رفع ومعالجة ملفات PDF وDOCX وTXT ضمن مكتبة
                            وثائق واحدة، مع متابعة حالة محاولات المعالجة.
                        </p>
                    </article>

                    <article class="landing-feature-card">
                        <div class="landing-icon">
                            <svg
                                viewBox="0 0 24 24"
                                class="size-7"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.7"
                                aria-hidden="true"
                            >
                                <path d="M6 4h12v16H6z" />
                                <path d="M9 8h6M9 12h6M9 16h4" />
                            </svg>
                        </div>

                        <h3>إجابات مع المصادر</h3>

                        <p>
                            يعرض النظام الوثائق والمقاطع المرتبطة
                            بالإجابة حتى تتمكن من مراجعة السياق المستخدم.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        {{-- =====================================================
            WORKFLOW
        ====================================================== --}}
        <section
            id="how-it-works"
            data-landing-workflow
            class="landing-section relative overflow-hidden border-b border-white/[0.07]"
        >
            <div
                class="landing-glow landing-glow-center"
                aria-hidden="true"
            ></div>

            <div class="landing-container">
                <header class="landing-section-heading">
                    <p class="landing-section-label">
                        كيف يعمل النظام؟
                    </p>

                    <h2>
                        من المستند إلى الإجابة في أربع مراحل
                    </h2>

                    <p>
                        مسار واضح يبدأ برفع الوثيقة وينتهي بإجابة مرتبطة
                        بالمصادر المستخدمة.
                    </p>
                </header>

                <div
                    class="landing-workflow relative mt-14 grid gap-10 md:grid-cols-2 xl:grid-cols-4"
                >
                    <div
                        class="landing-workflow-line"
                        aria-hidden="true"
                    ></div>

                    @foreach ([
                        [
                            'number' => '1',
                            'title' => 'رفع المستندات',
                            'text' => 'ارفع مستنداتك وحدد مسار المعالجة المتاح.',
                            'icon' => 'upload',
                        ],
                        [
                            'number' => '2',
                            'title' => 'معالجة المحتوى',
                            'text' => 'يتم استخراج النص وتقسيم المحتوى وتجهيزه للبحث.',
                            'icon' => 'process',
                        ],
                        [
                            'number' => '3',
                            'title' => 'البحث والاسترجاع',
                            'text' => 'يسترجع النظام المقاطع المرشحة ويرتب النتائج.',
                            'icon' => 'search',
                        ],
                        [
                            'number' => '4',
                            'title' => 'الإجابة مع المصادر',
                            'text' => 'يُبنى السياق ثم تُعرض الإجابة مع المصادر المرتبطة بها.',
                            'icon' => 'answer',
                        ],
                    ] as $step)
                        <article class="landing-step">
                            <div class="landing-step-icon">
                                @if ($step['icon'] === 'upload')
                                    <svg
                                        viewBox="0 0 24 24"
                                        class="size-8"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                        aria-hidden="true"
                                    >
                                        <path d="M12 16V4m0 0-4 4m4-4 4 4" />
                                        <path d="M5 14v5h14v-5" />
                                    </svg>
                                @elseif ($step['icon'] === 'process')
                                    <svg
                                        viewBox="0 0 24 24"
                                        class="size-8"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                        aria-hidden="true"
                                    >
                                        <circle cx="12" cy="12" r="3" />
                                        <path d="M12 2v3M12 19v3M4.9 4.9 7 7M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1" />
                                    </svg>
                                @elseif ($step['icon'] === 'search')
                                    <svg
                                        viewBox="0 0 24 24"
                                        class="size-8"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                        aria-hidden="true"
                                    >
                                        <circle cx="10.5" cy="10.5" r="6" />
                                        <path d="m15 15 5 5" />
                                    </svg>
                                @else
                                    <svg
                                        viewBox="0 0 24 24"
                                        class="size-8"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.7"
                                        aria-hidden="true"
                                    >
                                        <path d="M5 5h14v11H9l-4 4V5Z" />
                                        <path d="M9 9h6M9 12h4" />
                                    </svg>
                                @endif

                                <span>{{ $step['number'] }}</span>
                            </div>

                            <h3>{{ $step['title'] }}</h3>

                            <p>{{ $step['text'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- =====================================================
            PROCESSING PATHS
        ====================================================== --}}
        <section
            id="processing"
            data-landing-processing
            class="landing-section relative overflow-hidden border-b border-white/[0.07] bg-navy-900/30"
        >
            <div class="landing-container">
                <header class="landing-section-heading">
                    <p class="landing-section-label">
                        مرونة في بيئة التشغيل
                    </p>

                    <h2>
                        مسارات معالجة تناسب بيئة التشغيل
                    </h2>

                    <p>
                        يعتمد المسار المتاح على إعدادات البيئة،
                        مع إبقاء حالة كل محاولة معالجة مستقلة واختيار
                        النسخة الفعالة للوثيقة.
                    </p>
                </header>

                <div
                    class="mt-12 grid items-center gap-6 lg:grid-cols-[1fr_0.9fr_1fr]"
                >
                    <article class="landing-processing-card">
                        <div class="flex items-center justify-between gap-3">
                            <h3>Cloud</h3>

                            <div class="landing-small-icon">
                                <svg
                                    viewBox="0 0 24 24"
                                    class="size-6"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    aria-hidden="true"
                                >
                                    <path d="M5 17h13a4 4 0 0 0 .5-7.97A6.5 6.5 0 0 0 6.2 8.2 4.5 4.5 0 0 0 5 17Z" />
                                </svg>
                            </div>
                        </div>

                        <p>
                            يستخدم الخدمات السحابية المهيأة للمشروع
                            عندما يكون مسار Cloud متاحًا.
                        </p>

                        <ul>
                            <li>إعداد مركزي للخدمات</li>
                            <li>مناسب لبيئات العرض والتجربة</li>
                            <li>يتبع الإمكانات المتاحة وقت التشغيل</li>
                        </ul>
                    </article>

                    <div
                        data-landing-processing-visual
                        class="landing-processing-visual"
                        aria-label="تمثيل بصري لمسارات المعالجة المحلية والسحابية"
                    >
                        <div class="landing-processing-aura" aria-hidden="true"></div>

                        <div class="landing-server-stack" aria-hidden="true">
                            <div class="landing-server-unit">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>

                            <div class="landing-server-unit">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>

                            <div class="landing-server-unit">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>

                            <div class="landing-server-top">
                                <span></span>
                            </div>
                        </div>

                        <div class="landing-cloud" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <path d="M5 17h13a4 4 0 0 0 .5-7.97A6.5 6.5 0 0 0 6.2 8.2 4.5 4.5 0 0 0 5 17Z" />
                            </svg>
                        </div>

                        <div class="landing-processing-files" aria-hidden="true">
                            <div class="landing-processing-file landing-processing-pdf">
                                PDF
                            </div>

                            <div class="landing-processing-file landing-processing-docx">
                                DOCX
                            </div>

                            <div class="landing-processing-file landing-processing-txt">
                                TXT
                            </div>
                        </div>

                        <div class="landing-processing-lines" aria-hidden="true">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                    </div>

                    <article class="landing-processing-card landing-processing-card-accent">
                        <div class="flex items-center justify-between gap-3">
                            <h3>Hybrid Local</h3>

                            <div class="landing-small-icon">
                                <svg
                                    viewBox="0 0 24 24"
                                    class="size-6"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.7"
                                    aria-hidden="true"
                                >
                                    <path d="M5 5h14v5H5zM5 14h14v5H5z" />
                                    <path d="M16 7.5h.01M16 16.5h.01" />
                                </svg>
                            </div>
                        </div>

                        <p>
                            يستخدم مكونات المعالجة المحلية عندما يكون
                            المسار المحلي مفعّلًا في البيئة.
                        </p>

                        <ul>
                            <li>تحكم أكبر في بيئة التشغيل</li>
                            <li>مناسب للتجارب المحلية</li>
                            <li>يعمل وفق الموارد المتاحة للجهاز</li>
                        </ul>
                    </article>
                </div>
            </div>
        </section>

        {{-- =====================================================
            CTA
        ====================================================== --}}
        <section class="landing-cta relative overflow-hidden">
            <div
                class="landing-glow landing-glow-bottom"
                aria-hidden="true"
            ></div>

            <div class="landing-container text-center">
                <p class="landing-section-label">
                    ابدأ تجربتك
                </p>

                <h2>
                    جاهز لبدء العمل مع مستنداتك؟
                </h2>

                <p>
                    أنشئ حسابك، ارفع مستنداتك، وابدأ محادثة مرتبطة
                    بالوثائق التي تختارها.
                </p>

                <div class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                    <a
                        href="{{ route('register') }}"
                        class="landing-primary-button"
                    >
                        إنشاء حساب

                        <svg
                            viewBox="0 0 24 24"
                            class="size-5"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M19 12H5m0 0 5-5m-5 5 5 5"
                            />
                        </svg>
                    </a>

                    <a
                        href="{{ route('login') }}"
                        class="landing-secondary-button"
                    >
                        تسجيل الدخول
                    </a>
                </div>
            </div>
        </section>
    </div>
</x-layouts.marketing>
