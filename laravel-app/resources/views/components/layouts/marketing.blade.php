@props(['title' => null])

<!DOCTYPE html>
<html lang="ar" dir="rtl" class="dark scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >

        <title>
            {{ $title ? $title . ' | ' . config('app.name') : config('app.name') }}
        </title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="marketing-shell min-h-screen bg-navy-950 text-ice-100">
        <div class="relative isolate min-h-screen overflow-clip">
            <div
                class="landing-grid-bg"
                aria-hidden="true"
            ></div>

            <header class="landing-header">
                <nav
                    class="landing-nav"
                    aria-label="التنقل الرئيسي"
                >
                    <a
                        href="{{ url('/') }}"
                        class="shrink-0"
                        aria-label="الصفحة الرئيسية"
                    >
                        <x-brand compact />
                    </a>

                    <div class="landing-nav-links">
                        <a href="#home">الرئيسية</a>
                        <a href="#features">المميزات</a>
                        <a href="#how-it-works">كيف يعمل؟</a>
                        <a href="#processing">مسارات المعالجة</a>
                    </div>

                    <div class="flex items-center gap-2.5">
                        @auth
                            <a
                                href="{{ route('workspace') }}"
                                class="landing-nav-primary"
                            >
                                مساحة العمل
                            </a>

                            <form
                                method="POST"
                                action="{{ route('logout') }}"
                            >
                                @csrf

                                <button
                                    type="submit"
                                    class="landing-nav-secondary hidden sm:inline-flex"
                                >
                                    تسجيل الخروج
                                </button>
                            </form>
                        @else
                            <a
                                href="{{ route('login') }}"
                                class="landing-nav-secondary hidden sm:inline-flex"
                            >
                                تسجيل الدخول
                            </a>

                            <a
                                href="{{ route('register') }}"
                                class="landing-nav-primary"
                            >
                                إنشاء حساب
                            </a>
                        @endauth
                    </div>
                </nav>
            </header>

            <main>
                {{ $slot }}
            </main>

            <footer class="landing-footer">
                <div class="landing-footer-inner">
                    <x-brand compact />

                    <p>
                        منصة عربية للاستعلام عن المستندات باستخدام
                        التوليد المعزز بالاسترجاع.
                    </p>

                    <p>
                        &copy; {{ now()->year }}
                        {{ config('app.name') }}.
                    </p>
                </div>
            </footer>
        </div>
    </body>
</html>
