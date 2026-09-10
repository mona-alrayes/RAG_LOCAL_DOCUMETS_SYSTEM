<div class="flex min-h-0 flex-1 flex-col">
    @php
        $timingLabels = [
            'total' => 'إجمالي الوقت',
            'generation' => 'توليد الإجابة',
            'reranking' => 'إعادة الترتيب',
            'retrieval' => 'الاسترجاع',
            'context_building' => 'بناء السياق',
            'fusion' => 'دمج النتائج',
            'query_embedding' => 'تمثيل السؤال',
        ];

        $formatTiming = static function (
            int|float $milliseconds,
        ): string {
            if ($milliseconds > 0 && $milliseconds < 1) {
                return '< 1 ms';
            }

            $value = $milliseconds;
            $unit = 'ms';

            if ($milliseconds >= 1000) {
                $value = $milliseconds / 1000;
                $unit = 's';
            }

            $formatted = rtrim(
                rtrim(
                    number_format(
                        $value,
                        2,
                        '.',
                        '',
                    ),
                    '0',
                ),
                '.',
            );

            return $formatted.' '.$unit;
        };
    @endphp

    <div
        class="min-h-0 flex-1 overflow-y-auto px-4 py-6 sm:px-8"
        data-conversation-messages
    >
        @if ($messages->isEmpty())
            <div
                class="flex min-h-56 items-center justify-center text-center"
            >
                <p class="text-sm text-mist-400">
                    ابدأ بسؤال عن الوثائق المختارة.
                </p>
            </div>
        @else
            <div
                class="mx-auto flex w-full max-w-3xl flex-col gap-7"
            >
                @foreach ($messages as $message)
                    @php
                        $isAssistant =
                            $message->role
                            === \App\Enums\MessageRole::Assistant;

                        $isPending =
                            $message->status
                            === \App\Enums\MessageStatus::Pending;

                        $isFailed =
                            $message->status
                            === \App\Enums\MessageStatus::Failed;

                        $isCompleted =
                            $message->status
                            === \App\Enums\MessageStatus::Completed;

                        $metrics =
                            is_array($message->metrics)
                                ? $message->metrics
                                : [];

                        $timingRows = [];

                        if (
                            $isAssistant
                            && $isCompleted
                        ) {
                            foreach (
                                $timingLabels
                                as $key => $label
                            ) {
                                $value =
                                    $metrics[$key]
                                    ?? null;

                                if (
                                    (
                                        ! is_int($value)
                                        && ! is_float($value)
                                    )
                                    || $value < 0
                                    || ! is_finite(
                                        (float) $value,
                                    )
                                ) {
                                    continue;
                                }

                                $timingRows[$key] = [
                                    'label' =>
                                        $label,
                                    'value' =>
                                        $value,
                                ];
                            }
                        }

                        $basicTimingRows = [];
                        $technicalTimingRows = [];

                        foreach (
                            [
                                'total',
                                'retrieval',
                                'generation',
                            ] as $key
                        ) {
                            if (
                                array_key_exists(
                                    $key,
                                    $timingRows,
                                )
                            ) {
                                $basicTimingRows[$key] =
                                    $timingRows[$key];
                            }
                        }

                        foreach (
                            [
                                'query_embedding',
                                'reranking',
                                'fusion',
                                'context_building',
                            ] as $key
                        ) {
                            if (
                                array_key_exists(
                                    $key,
                                    $timingRows,
                                )
                            ) {
                                $technicalTimingRows[$key] =
                                    $timingRows[$key];
                            }
                        }

                        $hasTimings =
                            array_key_exists(
                                'total',
                                $basicTimingRows,
                            );
                    @endphp

                    <article
                        wire:key="conversation-message-{{ $message->id }}"
                        data-conversation-message="{{ $message->id }}"
                        @if ($isAssistant && $isPending)
                            data-stream-url="{{ route(
                                'conversations.answers.stream',
                                [
                                    'conversation' => $conversation,
                                    'message' => $message,
                                ],
                            ) }}"
                            data-stream-assistant-id="{{ $message->id }}"
                            x-data
                            x-init="window.startConversationAnswerStream($el)"
                        @endif
                        class="{{ $isAssistant
                            ? 'w-full'
                            : 'me-auto max-w-[85%]' }}"
                    >
                        <div
                            class="{{ $isAssistant
                                ? 'px-1 py-2 text-ice-100'
                                : 'rounded-3xl bg-white/10 px-4 py-3 text-ice-100' }}"
                        >
                            @if (
                                $isAssistant
                                && $isPending
                            )
                                <div
                                    wire:key="conversation-pending-content-{{ $message->id }}"
                                    wire:ignore
                                    data-stream-content
                                    role="status"
                                    aria-live="polite"
                                    aria-atomic="false"
                                    aria-busy="true"
                                    class="conversation-markdown text-sm leading-7 text-mist-300"
                                >جاري إعداد الإجابة...</div>
                            @elseif (
                                $isAssistant
                                && $isFailed
                            )
                                <p
                                    role="alert"
                                    class="whitespace-pre-wrap break-words text-sm leading-7"
                                >تعذر إنشاء الإجابة.</p>
                            @elseif ($isAssistant)
                                <div
                                    wire:key="conversation-completed-content-{{ $message->id }}"
                                    data-assistant-markdown
                                    data-markdown-source="{{ $message->content }}"
                                    class="conversation-markdown text-sm leading-7"
                                >{{ $message->content }}</div>
                            @else
                                <p
                                    class="whitespace-pre-wrap break-words text-sm leading-7"
                                >{{ $message->content }}</p>
                            @endif
                        </div>

                        @if (
                            $isAssistant
                            && $isFailed
                        )
                            <button
                                type="button"
                                wire:click="retry({{ $message->id }})"
                                wire:loading.attr="disabled"
                                class="mt-2 inline-flex min-h-9 items-center rounded-xl border border-white/10 bg-navy-900/70 px-3 py-2 text-xs font-semibold text-cyan-300 transition hover:border-cyan-400/30 hover:bg-white/5 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                إعادة المحاولة
                            </button>
                        @endif

                        @if (
                            $isAssistant
                            && $isCompleted
                            && $message->sources->isNotEmpty()
                        )
                            <div
                                x-data="{ open: false }"
                                class="mt-2"
                            >
                                <button
                                    type="button"
                                    data-message-source-trigger
                                    @click="open = true"
                                    :aria-expanded="open.toString()"
                                    aria-controls="conversation-sources-dialog-{{ $message->id }}"
                                    class="inline-flex min-h-9 items-center gap-2 rounded-xl border border-white/10 bg-navy-900/70 px-3 py-2 text-xs font-semibold text-cyan-300 transition hover:border-cyan-400/30 hover:bg-white/5"
                                >
                                    <span>
                                        عرض المصادر
                                    </span>

                                    <span
                                        class="inline-flex min-w-5 items-center justify-center rounded-full bg-cyan-400/10 px-1.5"
                                    >
                                        {{ $message->sources->count() }}
                                    </span>
                                </button>

                                <div
                                    x-show="open"
                                    x-transition.opacity
                                    @keydown.escape.window="open = false"
                                    class="fixed inset-0 z-50"
                                    style="display: none;"
                                    id="conversation-sources-dialog-{{ $message->id }}"
                                    role="dialog"
                                    aria-modal="true"
                                    aria-labelledby="conversation-sources-title-{{ $message->id }}"
                                    data-message-sources-drawer="{{ $message->id }}"
                                >
                                    <button
                                        type="button"
                                        class="absolute inset-0 bg-black/60"
                                        aria-label="إغلاق المصادر"
                                        @click="open = false"
                                    ></button>

                                    <aside
                                        dir="rtl"
                                        class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col border-l border-white/10 bg-navy-950 shadow-2xl"
                                    >
                                        <header
                                            class="flex items-start justify-between gap-4 border-b border-white/10 px-5 py-4"
                                        >
                                            <div>
                                                <h2
                                                    id="conversation-sources-title-{{ $message->id }}"
                                                    class="text-base font-semibold text-ice-100"
                                                >
                                                    مصادر الإجابة
                                                </h2>

                                                <p
                                                    class="mt-1 text-xs text-mist-400"
                                                >
                                                    {{ $message->sources->count() }}
                                                    مصدر
                                                </p>
                                            </div>

                                            <button
                                                type="button"
                                                @click="open = false"
                                                class="inline-flex size-9 items-center justify-center rounded-xl border border-white/10 text-mist-300 hover:bg-white/5"
                                                aria-label="إغلاق"
                                            >
                                                ✕
                                            </button>
                                        </header>

                                        <div
                                            class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4 sm:p-5"
                                        >
                                            @foreach (
                                                $message->sources
                                                as $source
                                            )
                                                @php
                                                    $snapshot =
                                                        $source->source_snapshot
                                                        ?? [];

                                                    $document =
                                                        $source
                                                            ->processingRun
                                                            ->document;

                                                    $sourceName =
                                                        $document->title
                                                        ?: $document->original_name;

                                                    $page =
                                                        $snapshot['page']
                                                        ?? null;

                                                    $section =
                                                        $snapshot['section']
                                                        ?? null;

                                                    $excerpt =
                                                        $snapshot['excerpt']
                                                        ?? null;
                                                @endphp

                                                <section
                                                    data-message-source-card
                                                    class="rounded-2xl border border-white/10 bg-navy-900/70 p-4"
                                                >
                                                    <h3
                                                        class="break-words text-sm font-semibold text-ice-100"
                                                    >
                                                        {{ $sourceName }}
                                                    </h3>

                                                    @if (
                                                        $page !== null
                                                        || (
                                                            is_string($section)
                                                            && trim($section) !== ''
                                                        )
                                                    )
                                                        <div
                                                            class="mt-2 flex flex-wrap gap-3 text-xs text-mist-400"
                                                        >
                                                            @if ($page !== null)
                                                                <span>
                                                                    الصفحة
                                                                    {{ $page }}
                                                                </span>
                                                            @endif

                                                            @if (
                                                                is_string($section)
                                                                && trim($section) !== ''
                                                            )
                                                                <span>
                                                                    {{ $section }}
                                                                </span>
                                                            @endif
                                                        </div>
                                                    @endif

                                                    @if (
                                                        is_string($excerpt)
                                                        && trim($excerpt) !== ''
                                                    )
                                                        <blockquote
                                                            class="mt-3 whitespace-pre-wrap break-words rounded-xl border border-white/5 bg-navy-950/60 px-3 py-3 text-sm leading-7 text-mist-200"
                                                        >
                                                            {{ $excerpt }}
                                                        </blockquote>
                                                    @endif

                                                    @if (
                                                        $source->reranker_score
                                                        !== null
                                                    )
                                                        <div
                                                            class="mt-3 flex items-center justify-between gap-3 border-t border-white/10 pt-3 text-xs"
                                                        >
                                                            <span
                                                                class="text-mist-400"
                                                            >
                                                                درجة إعادة الترتيب
                                                            </span>

                                                            <span
                                                                data-source-reranker-score
                                                                class="font-mono font-medium text-cyan-300"
                                                                dir="ltr"
                                                            >
                                                                {{
                                                                    rtrim(
                                                                        rtrim(
                                                                            number_format(
                                                                                $source->reranker_score,
                                                                                6,
                                                                                '.',
                                                                                '',
                                                                            ),
                                                                            '0',
                                                                        ),
                                                                        '.',
                                                                    )
                                                                }}
                                                            </span>
                                                        </div>
                                                    @endif
                                                </section>
                                            @endforeach
                                        </div>
                                    </aside>
                                </div>
                            </div>
                        @endif

                        @if (
                            $isAssistant
                            && $isCompleted
                            && $hasTimings
                        )
                            <details
                                data-message-timings="{{ $message->id }}"
                                class="mt-2 w-fit max-w-full"
                            >
                                <summary
                                    data-message-timings-trigger
                                    class="inline-flex min-h-9 cursor-pointer list-none items-center gap-1.5 rounded-xl border border-white/10 bg-navy-900/70 px-3 py-2 text-xs font-semibold text-cyan-300"
                                >
                                    <span>
                                        التوقيتات
                                    </span>

                                    <span
                                        class="font-normal text-mist-400"
                                    >
                                        ·
                                        {{
                                            $formatTiming(
                                                $timingRows[
                                                    'total'
                                                ][
                                                    'value'
                                                ],
                                            )
                                        }}
                                    </span>
                                </summary>

                                <div
                                    data-message-timings-panel="{{ $message->id }}"
                                    class="mt-2 min-w-72 max-w-sm overflow-hidden rounded-2xl border border-white/10 bg-navy-900/90 p-3 shadow-xl"
                                >
                                    <div
                                        class="space-y-1"
                                        data-message-basic-timings
                                    >
                                        @foreach (
                                            $basicTimingRows
                                            as $key => $timing
                                        )
                                            <div
                                                data-message-timing="{{ $key }}"
                                                class="flex items-center justify-between gap-6 border-b border-white/5 px-1 py-2 text-xs last:border-b-0"
                                            >
                                                <span
                                                    class="{{ $key === 'total'
                                                        ? 'font-semibold text-ice-100'
                                                        : 'text-mist-400' }}"
                                                >
                                                    {{ $timing['label'] }}
                                                </span>

                                                <span
                                                    class="{{ $key === 'total'
                                                        ? 'font-semibold text-cyan-300'
                                                        : 'font-medium text-mist-200' }}"
                                                >
                                                    {{
                                                        $formatTiming(
                                                            $timing[
                                                                'value'
                                                            ],
                                                        )
                                                    }}
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>

                                    <details
                                        class="mt-2 border-t border-white/10 pt-2"
                                        data-message-technical-timings
                                    >
                                        <summary
                                            class="cursor-pointer list-none px-1 py-2 text-xs font-semibold text-mist-300"
                                        >
                                            التفاصيل التقنية
                                        </summary>

                                        <div
                                            class="mt-1 rounded-xl bg-navy-950/50 px-2 py-1"
                                        >
                                            @foreach (
                                                $technicalTimingRows
                                                as $key => $timing
                                            )
                                                <div
                                                    data-message-timing="{{ $key }}"
                                                    class="flex items-center justify-between gap-6 border-b border-white/5 px-1 py-2 text-xs last:border-b-0"
                                                >
                                                    <span
                                                        class="text-mist-400"
                                                    >
                                                        {{ $timing['label'] }}
                                                    </span>

                                                    <span
                                                        class="font-medium text-mist-200"
                                                    >
                                                        {{
                                                            $formatTiming(
                                                                $timing[
                                                                    'value'
                                                                ],
                                                            )
                                                        }}
                                                    </span>
                                                </div>
                                            @endforeach

                                            <div
                                                class="flex items-start justify-between gap-6 border-t border-white/5 px-1 py-2 text-xs"
                                                data-message-retrieval-strategy
                                            >
                                                <span
                                                    class="text-mist-400"
                                                >
                                                    استراتيجية الاسترجاع
                                                </span>

                                                <span
                                                    class="text-left font-mono text-[11px] leading-5 text-mist-200"
                                                    dir="ltr"
                                                >
                                                    Dense + Sparse → RRF → Reranker
                                                </span>
                                            </div>
                                        </div>
                                    </details>
                                </div>
                            </details>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>

    <div
        class="shrink-0 bg-gradient-to-t from-navy-950 via-navy-950 to-transparent px-4 pb-4 pt-3 sm:px-8 sm:pb-5"
    >
        <form
            wire:submit="ask"
            data-conversation-composer
            x-data
            x-init="window.initializeConversationComposer($el)"
            class="mx-auto w-full max-w-3xl"
        >
            <div
                class="flex items-end gap-2 rounded-[1.75rem] border border-white/10 bg-navy-900/95 p-2 shadow-2xl shadow-black/20 transition focus-within:border-cyan-400/30"
            >
                <label
                    for="conversation-question"
                    class="sr-only"
                >
                    السؤال
                </label>

                <textarea
                    id="conversation-question"
                    data-conversation-question
                    wire:model="question"
                    rows="1"
                    placeholder="اكتب سؤالك عن الوثائق المختارة..."
                    @error('question')
                        aria-invalid="true"
                        aria-describedby="conversation-question-error"
                    @enderror
                    class="min-h-11 flex-1 resize-none border-0 bg-transparent px-3 py-2.5 text-sm leading-6 text-ice-100 outline-none placeholder:text-mist-500 focus:ring-0"
                ></textarea>

                <button
                    type="submit"
                    data-conversation-submit
                    wire:loading.attr="disabled"
                    wire:target="ask"
                    aria-label="إرسال السؤال"
                    title="إرسال"
                    class="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-cyan-400 text-navy-950 transition hover:bg-cyan-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-300 disabled:cursor-not-allowed disabled:opacity-40"
                >
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
                            d="M12 19V5m0 0-5 5m5-5 5 5"
                        />
                    </svg>
                </button>
            </div>

            <p class="mt-2 px-3 text-center text-[11px] text-mist-500">
                Enter للإرسال · Shift + Enter لسطر جديد
            </p>

            @error('question')
                <p
                    id="conversation-question-error"
                    role="alert"
                    class="mt-2 px-3 text-xs text-red-300"
                >
                    {{ $message }}
                </p>
            @enderror

            @error('retry')
                <p
                    role="alert"
                    class="mt-2 px-3 text-xs text-red-300"
                >
                    {{ $message }}
                </p>
            @enderror
        </form>
    </div>
</div>
