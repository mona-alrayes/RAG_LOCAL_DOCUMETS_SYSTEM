<x-filament-panels::page>
    <p>Read-only chunks for processing run #{{ $run }}. Vectors are never returned.</p>
    @if($error)
        <p role="alert">{{ $error }}</p>
        <x-filament::button wire:click="loadChunks">Try again</x-filament::button>
    @else
        <p>Total chunks: {{ $data['total'] ?? 0 }} · Page {{ count($cursors) }}</p>
        @forelse($data['chunks'] ?? [] as $chunk)
            <x-filament::section :heading="'Chunk '.$chunk['chunk_index'].' · '.$chunk['source']">
                <p>Point: {{ $chunk['point_id'] }} · Page: {{ $chunk['page'] ?? '—' }} · Section: {{ $chunk['section'] ?? '—' }}</p>
                <div dir="auto" style="white-space: pre-wrap; overflow-wrap: anywhere">{{ $chunk['text'] }}</div>
            </x-filament::section>
        @empty <p>No indexed chunks found.</p> @endforelse
    @endif
    <div class="flex gap-3">
        <x-filament::button wire:click="previousPage" :disabled="count($cursors) <= 1" wire:loading.attr="disabled">Previous</x-filament::button>
        <x-filament::button wire:click="nextPage" :disabled="empty($data['next_cursor'])" wire:loading.attr="disabled">Next</x-filament::button>
    </div>
</x-filament-panels::page>
