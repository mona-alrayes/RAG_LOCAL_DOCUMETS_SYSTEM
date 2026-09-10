<div
    class="relative flex w-full min-w-0 shrink-0 flex-col items-end gap-2 sm:w-auto sm:max-w-[60vw]"
    data-chat-document-selector
    @if ($pollRequired)
        wire:poll.visible.5s="refreshDocuments"
    @endif
>
    <details class="group relative">
        <summary
            class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-xl border border-white/10 bg-navy-900/70 px-3 py-2 text-sm font-semibold text-ice-100 transition hover:border-cyan-400/30 hover:bg-white/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400 [&::-webkit-details-marker]:hidden"
        >
            <svg
                viewBox="0 0 24 24"
                class="size-4 shrink-0 text-cyan-300"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                aria-hidden="true"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M3.75 6.75A2.25 2.25 0 0 1 6 4.5h4.19c.597 0 1.17.237 1.591.659l1.06 1.06c.422.422.994.659 1.591.659H18A2.25 2.25 0 0 1 20.25 9.128v7.122A2.25 2.25 0 0 1 18 18.5H6a2.25 2.25 0 0 1-2.25-2.25v-9.5Z"
                />
            </svg>

            <span>اختيار الوثائق</span>

            @if (count($selectedDocumentIds) > 0)
                <span
                    class="inline-flex min-w-5 items-center justify-center rounded-full bg-cyan-400/10 px-1.5 text-xs text-cyan-300"
                >
                    {{ count($selectedDocumentIds) }}
                </span>
            @endif

            <svg
                viewBox="0 0 20 20"
                class="size-4 shrink-0 text-mist-400 transition-transform group-open:rotate-180"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    fill-rule="evenodd"
                    d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.51a.75.75 0 0 1-1.08 0l-4.25-4.51a.75.75 0 0 1 .02-1.06Z"
                    clip-rule="evenodd"
                />
            </svg>
        </summary>

        <div
            class="absolute end-0 z-30 mt-2 w-80 max-w-[calc(100vw-2.5rem)] overflow-hidden rounded-2xl border border-white/10 bg-navy-900 shadow-2xl shadow-black/30"
        >
            <div class="border-b border-white/10 px-4 py-3">
                <h2 class="text-sm font-semibold text-ice-100">
                    وثائق المحادثة
                </h2>

                <p class="mt-1 text-xs leading-5 text-mist-400">
                    اختر وثيقة واحدة أو أكثر. الوثائق غير الجاهزة تبقى
                    محددة وتصبح قابلة للاستخدام تلقائيًا بعد اكتمال المعالجة.
                </p>
            </div>

            @if (session('success'))
                <div
                    role="status"
                    class="m-3 rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-3 py-2 text-xs text-emerald-200"
                >
                    {{ session('success') }}
                </div>
            @endif

            @if ($documents->isEmpty())
                <div class="p-5 text-center">
                    <p class="text-sm font-medium text-ice-100">
                        لا توجد وثائق متاحة للاختيار
                    </p>

                    <p class="mt-2 text-xs leading-5 text-mist-400">
                        ارفع وثيقة أولًا ثم عد إلى المحادثة.
                    </p>

                    <a
                        href="{{ route('documents.index') }}"
                        wire:navigate
                        class="mt-4 inline-flex min-h-9 items-center justify-center rounded-xl bg-cyan-400 px-3 py-2 text-xs font-semibold text-navy-950 transition hover:bg-cyan-300"
                    >
                        الذهاب إلى الوثائق
                    </a>
                </div>
            @else
                <form wire:submit="save">
                    @if ($errors->has('document_ids') || $errors->has('document_ids.*'))
                        <div
                            role="alert"
                            class="m-3 rounded-xl border border-red-400/20 bg-red-400/10 px-3 py-2 text-xs text-red-200"
                        >
                            {{
                                $errors->first('document_ids')
                                    ?: $errors->first('document_ids.*')
                            }}
                        </div>
                    @endif

                    <div class="max-h-80 space-y-1 overflow-y-auto p-2">
                        @foreach ($documents as $document)
                            @php
                                $isReady =
                                    $document->availability
                                    === \App\Enums\DocumentAvailability::Ready;
                            @endphp

                            <label
                                wire:key="conversation-document-{{ $document->id }}"
                                for="document-{{ $document->id }}"
                                class="flex cursor-pointer items-start gap-3 rounded-xl border border-transparent p-3 transition hover:border-white/10 hover:bg-white/5"
                            >
                                <input
                                    id="document-{{ $document->id }}"
                                    type="checkbox"
                                    value="{{ $document->id }}"
                                    wire:model="selectedDocumentIds"
                                    class="mt-1 size-4 shrink-0 rounded border-white/20 bg-navy-950 text-cyan-400 focus:ring-cyan-400"
                                >

                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate text-sm font-medium text-ice-100"
                                    >
                                        {{
                                            $document->title
                                                ?: $document->originalName
                                        }}
                                    </span>

                                    @if (
                                        $document->title
                                        && $document->title !== $document->originalName
                                    )
                                        <span
                                            class="mt-0.5 block truncate text-xs text-mist-400"
                                        >
                                            {{ $document->originalName }}
                                        </span>
                                    @endif

                                    <span class="mt-2 block">
                                        <x-documents.status-indicator
                                            :availability="$document->availability"
                                            :label="
                                                $isReady
                                                    ? 'جاهزة للاستخدام'
                                                    : null
                                            "
                                        />
                                    </span>

                                    @if ($document->reprocessingInProgress)
                                        <span
                                            class="mt-1.5 block text-xs leading-5 text-cyan-300"
                                        >
                                            إعادة المعالجة جارية، والنسخة
                                            الفعالة السابقة ما زالت جاهزة.
                                        </span>
                                    @elseif (! $isReady)
                                        <span
                                            class="mt-1.5 block text-xs leading-5 text-mist-400"
                                        >
                                            ستصبح قابلة للاستخدام تلقائيًا
                                            بعد اكتمال المعالجة.
                                        </span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div
                        class="flex items-center justify-between gap-3 border-t border-white/10 p-3"
                    >
                        <div class="min-w-0 text-xs">
                            @if ($saved)
                                <span role="status" class="text-emerald-300">
                                    تم حفظ الاختيار
                                </span>
                            @else
                                <span class="text-mist-400">
                                    {{ count($selectedDocumentIds) }}
                                    محددة
                                </span>
                            @endif
                        </div>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            class="inline-flex min-h-9 shrink-0 items-center justify-center rounded-xl bg-cyan-400 px-4 py-2 text-xs font-semibold text-navy-950 transition hover:bg-cyan-300 disabled:cursor-wait disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="save">
                                حفظ
                            </span>

                            <span wire:loading wire:target="save">
                                جارٍ الحفظ...
                            </span>
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </details>

    @if ($selectedDocuments->isNotEmpty())
        <div
            data-selected-document-chips
            class="flex w-full flex-wrap justify-end gap-1.5 sm:w-auto"
            aria-label="الوثائق المختارة"
        >
            @foreach ($selectedDocuments as $document)
                <span
                    wire:key="selected-document-chip-{{ $document->id }}"
                    data-selected-document-chip="{{ $document->id }}"
                    title="{{ $document->title ?: $document->originalName }}"
                    class="inline-flex max-w-48 items-center gap-1.5 rounded-full border border-cyan-400/20 bg-cyan-400/10 px-2.5 py-1 text-xs font-medium text-cyan-100"
                >
                    <span
                        class="size-1.5 shrink-0 rounded-full bg-cyan-300"
                        aria-hidden="true"
                    ></span>

                    <span class="truncate">
                        {{ $document->title ?: $document->originalName }}
                    </span>
                </span>
            @endforeach
        </div>
    @endif
</div>
