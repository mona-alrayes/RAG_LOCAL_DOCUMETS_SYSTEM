<x-layouts.app
    title="المحادثات"
    full-bleed
    :conversations="$conversations"
>
    <section
        class="flex h-[calc(100dvh-4rem)] min-h-0 w-full flex-col bg-navy-950 lg:h-dvh"
        aria-label="مساحة المحادثات"
    >
        <div class="flex min-h-0 flex-1 items-center justify-center px-6 py-12">
            <div class="mx-auto max-w-md text-center">
                <div
                    class="mx-auto flex size-14 items-center justify-center rounded-2xl border border-cyan-400/20 bg-cyan-400/10 text-cyan-300"
                    aria-hidden="true"
                >
                    <svg
                        viewBox="0 0 24 24"
                        class="size-7"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"
                        />
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M2.25 12c0 4.142 4.365 7.5 9.75 7.5 1.09 0 2.138-.138 3.116-.393.78.421 1.858.846 3.309.846.346 0 .686-.024 1.018-.07a.75.75 0 0 0 .507-1.173 8.954 8.954 0 0 1-.978-1.77C20.685 15.593 21.75 13.891 21.75 12c0-4.142-4.365-7.5-9.75-7.5S2.25 7.858 2.25 12Z"
                        />
                    </svg>
                </div>

                <h1 class="mt-5 text-2xl font-bold text-ice-100">
                    ابدأ محادثة جديدة
                </h1>

                <p class="mt-3 text-sm leading-7 text-mist-300">
                    أنشئ محادثة جديدة أو افتح إحدى محادثاتك السابقة
                    من قائمة المحادثات.
                </p>

                <form
                    method="POST"
                    action="{{ route('conversations.store') }}"
                    class="mt-6"
                >
                    @csrf

                    <button
                        type="submit"
                        class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-semibold text-navy-950 transition hover:bg-cyan-300"
                    >
                        <span>محادثة جديدة</span>

                        <svg
                            viewBox="0 0 24 24"
                            class="size-4"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 6v12m6-6H6"
                            />
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </section>
</x-layouts.app>
