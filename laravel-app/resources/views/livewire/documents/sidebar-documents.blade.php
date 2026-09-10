<div
    class="mt-1 space-y-1 pe-2 ps-3"
    @if ($pollRequired)
        wire:poll.visible.10s="refreshDocuments"
    @endif
>
    @forelse ($documents as $document)
        <div
            @class([
                'flex min-w-0 items-start gap-1 rounded-lg transition',
                'bg-white/5' => $activeDocumentId === $document->id,
                'hover:bg-white/5' => $activeDocumentId !== $document->id,
            ])
        >
            <a
                href="{{ route('documents.show', $document->id) }}"
                wire:navigate
                class="min-w-0 flex-1 px-3 py-2"
            >
                <p
                    @class([
                        'truncate text-sm font-medium transition',
                        'text-cyan-300' => $activeDocumentId === $document->id,
                        'text-ice-100' => $activeDocumentId !== $document->id,
                    ])
                >
                    {{ $document->title ?: $document->originalName }}
                </p>

                <x-documents.status-indicator
                    :availability="$document->availability"
                    class="mt-1"
                />

                @if ($document->reprocessingInProgress)
                    <p class="mt-1 text-xs text-cyan-300">
                        إعادة معالجة جارية
                    </p>
                @endif
            </a>

            <div class="pt-1">
                <x-documents.actions-menu
                    :document="$document"
                    :available-processing-profiles="$availableProcessingProfiles"
                />
            </div>
        </div>
    @empty
        <p class="px-3 py-3 text-xs leading-5 text-mist-300">
            لا توجد وثائق بعد.
        </p>
    @endforelse

    <a
        href="{{ route('documents.index') }}"
        wire:navigate
        @class([
            'flex min-h-10 items-center rounded-lg px-3 py-2 text-xs font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-400',
            'bg-white/5 text-cyan-200' => request()->routeIs('documents.index'),
            'text-cyan-300 hover:bg-white/5 hover:text-cyan-200' => ! request()->routeIs('documents.index'),
        ])
    >
        عرض كل الوثائق
    </a>
</div>
