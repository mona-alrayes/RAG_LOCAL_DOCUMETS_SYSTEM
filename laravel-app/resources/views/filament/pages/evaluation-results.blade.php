<x-filament-panels::page>
    <div @if(in_array($evaluation->status, ['queued', 'running'], true)) wire:poll.5s @endif class="space-y-6">
        <x-filament::section :heading="$evaluation->name">
            <p role="status">Status: {{ $evaluation->status }} · {{ $evaluation->completed_questions }} / {{ $evaluation->questions_count }} questions</p>
            <p>Dataset: {{ $evaluation->dataset_version }} · K = {{ $evaluation->k }}</p>
            <p style="overflow-wrap:anywhere">SHA-256: {{ $evaluation->dataset_sha256 }}</p>
            <p>Started: {{ $evaluation->started_at ?? 'Waiting for queue worker' }} · Finished: {{ $evaluation->completed_at ?? '—' }}</p>
            @if($evaluation->status === 'queued') <p>The background worker will start the evaluation. This page updates automatically.</p> @endif
            @if($evaluation->error_code) <p role="alert">Evaluation stopped: {{ $evaluation->error_code }}. No final metrics were published. Check the service, document index, and queue, then upload again to start a new run.</p> @endif
        </x-filament::section>
        @if($evaluation->metrics)
            <x-filament::section heading="Retrieval metrics — macro average across questions">
                <div class="evaluation-metrics">
                @foreach(['precision_at_k' => 'Precision@K', 'recall_at_k' => 'Recall@K', 'hit_rate_at_k' => 'Hit Rate@K', 'mrr_at_k' => 'MRR@K', 'ndcg_at_k' => 'nDCG@K'] as $key => $label)
                    <div class="evaluation-metric"><p>{{ $label }}</p><strong style="font-size:1.75rem">{{ number_format($evaluation->metrics[$key], 4) }}</strong></div>
                @endforeach
                </div>
                <p>Binary chunk relevance; missing ranks count as misses. MRR is truncated to K. Faithfulness, answer correctness, and answer relevance are not computed in this retrieval-only benchmark.</p>
                <p>Mean retrieval latency: {{ number_format($evaluation->latency_summary['mean_ms'], 1) }} ms · p95: {{ number_format($evaluation->latency_summary['p95_ms'], 1) }} ms</p>
            </x-filament::section>
        @endif
        @if($evaluation->config_snapshot)
            @include('filament.pages.partials-evaluation-snapshot')
        @endif
        <x-filament::section heading="Per-question results" collapsible>
            @forelse($evaluation->results ?? [] as $result)
                <details class="py-3">
                    <summary>Question {{ $result['example'] }} · {{ number_format($result['latency_ms'], 1) }} ms</summary>
                    @foreach($result['metrics'] as $metric => $value) <p>{{ $metric }}: {{ number_format($value, 4) }}</p> @endforeach
                    @foreach($result['retrieved'] as $rank => $chunk) <p>Rank {{ $rank + 1 }} · Document #{{ $chunk['document_id'] }} · Run #{{ $chunk['processing_run_id'] }} · Chunk {{ $chunk['chunk_index'] }} · {{ $chunk['processing_profile'] }}</p> @endforeach
                </details>
            @empty <p>No results yet.</p> @endforelse
        </x-filament::section>
        <a href="{{ \App\Filament\Resources\EvaluationRunResource::getUrl() }}">Evaluation history</a>
    </div>
</x-filament-panels::page>
