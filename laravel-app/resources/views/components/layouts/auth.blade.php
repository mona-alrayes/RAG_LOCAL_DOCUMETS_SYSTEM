{{-- غلاف صفحات تسجيل الدخول والتسجيل واستعادة كلمة المرور --}}

@props([
    'title',
    'heading',
    'description',
])

<!DOCTYPE html>
<html lang="ar" dir="rtl" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }} | {{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>

    <body class="min-h-screen bg-navy-950">
        <div class="relative min-h-screen overflow-hidden">
            <div
                class="pointer-events-none absolute inset-0 opacity-30"
                aria-hidden="true"
                style="background-image:
                    linear-gradient(rgba(0, 229, 255, 0.04) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(0, 229, 255, 0.04) 1px, transparent 1px);
                    background-size: 44px 44px;"
            ></div>

            <div
                class="pointer-events-none absolute -right-48 -top-48 size-125 rounded-full bg-cyan-400/10 blur-3xl"
                aria-hidden="true"
            ></div>

            <main class="relative mx-auto grid min-h-screen max-w-7xl lg:grid-cols-[1.05fr_0.95fr]">
                <aside class="hidden border-l border-white/10 px-12 py-14 lg:flex lg:flex-col lg:justify-between">
                    <x-brand />

                    <div class="max-w-xl">
                        <p class="mb-5 text-sm font-semibold tracking-wider text-cyan-400">
                            معرفة موثّقة من ملفاتك
                        </p>

                        <p class="text-4xl font-semibold leading-[1.45] text-ice-100 xl:text-5xl">
                            حوّل وثائقك المحلية إلى معرفة قابلة للاستعلام.
                        </p>

                        <p class="mt-6 max-w-lg text-lg leading-8 text-mist-300">
                            ابحث داخل ملفاتك واحصل على إجابات مرتبطة بالمصادر،
                            ضمن مساحة خاصة ومنظمة.
                        </p>
                    </div>

                    <p class="text-sm text-mist-300/70">
                        الخصوصية، قابلية التتبع، وفصل واضح بين الوثائق والمحادثات.
                    </p>
                </aside>

                <section class="flex min-h-screen items-center justify-center px-5 py-10 sm:px-8 lg:px-12">
                    <div class="w-full max-w-md">
                        <x-brand compact class="mb-8 lg:hidden" />

                        <div class="rounded-xl border border-white/10 bg-navy-900/80 p-6 shadow-2xl shadow-black/30 backdrop-blur-xl sm:p-8">
                            <header class="mb-8">
                                <h1 class="text-2xl font-semibold text-ice-100">
                                    {{ $heading }}
                                </h1>

                                <p class="mt-2 text-sm leading-6 text-mist-300">
                                    {{ $description }}
                                </p>
                            </header>

                            @if ($errors->any())
                                <div
                                    class="mb-6 rounded-lg border border-red-400/20 bg-red-400/10 px-4 py-3 text-sm text-red-300"
                                    role="alert"
                                    aria-live="polite"
                                >
                                    <ul class="space-y-1">
                                        @foreach ($errors->all() as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            {{ $slot }}
                        </div>
                    </div>
                </section>
            </main>
        </div>

        @livewireScripts
        @fluxScripts
    </body>
</html>