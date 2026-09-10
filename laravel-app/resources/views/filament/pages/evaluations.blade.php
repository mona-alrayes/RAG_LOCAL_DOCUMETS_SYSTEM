<x-filament-panels::page>
    <x-filament::section heading="Evaluate retrieval quality">
        <p>Upload labelled questions to run the current retrieval pipeline and save its metrics. Each question uses the selected documents as its search corpus. Include distractor documents when appropriate for a meaningful benchmark.</p>
        <p>Find document IDs in Documents and chunk indices using Chunks → عرض المقاطع. All documents for one question must belong to the same owner and be ready. Replace the example IDs in the template.</p>
        <p>Results: Precision@K, Recall@K, Hit Rate@K, MRR@K, nDCG@K, and retrieval latency. Expected answers are optional reference data. Faithfulness, answer correctness, and answer relevance are not scored in this retrieval-only benchmark.</p>
        <x-filament::button wire:click="downloadExcelTemplate" color="primary">تنزيل قالب Excel</x-filament::button>
        <x-filament::button wire:click="downloadTemplate" color="gray">Download JSON template</x-filament::button>
    </x-filament::section>
    <form wire:submit="submit" class="space-y-6">
        {{ $this->form }}
        @error('data.dataset') <p role="alert">{{ $message }}</p> @enderror
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="submit,data.dataset">Upload and evaluate</x-filament::button>
    </form>
</x-filament-panels::page>
