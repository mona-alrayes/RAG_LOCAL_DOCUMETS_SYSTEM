{{--مكون مشترك يمكن استعماله في جميع القوالب --}}

@props(['compact' => false])

<div {{ $attributes->class(['flex items-center gap-3']) }}>
    <span
        class="flex size-11 shrink-0 items-center justify-center rounded-lg border border-cyan-400/30 bg-cyan-400/10 text-cyan-400 shadow-[0_0_24px_rgba(0,229,255,0.12)]"
        aria-hidden="true"
    >
        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.7">
            <path d="M7 3.75h7.5L19 8.25v11A1.75 1.75 0 0 1 17.25 21H7a2 2 0 0 1-2-2V5.75A2 2 0 0 1 7 3.75Z" />
            <path d="M14 3.75v4.5h5M8.5 12h7M8.5 15.5h4.5" />
        </svg>
    </span>

    <div class="min-w-0">
        <p class="font-semibold leading-6 text-ice-100">
            نظام الاستعلام الذكي عن الوثائق
        </p>

        @unless ($compact)
            <p class="text-sm text-mist-300">
                باستخدام التوليد المعزز بالاسترجاع (RAG)
            </p>
        @endunless
    </div>
</div>