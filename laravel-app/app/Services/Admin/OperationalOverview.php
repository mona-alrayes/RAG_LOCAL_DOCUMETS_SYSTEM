<?php

namespace App\Services\Admin;

use App\Models\Document;
use App\Models\ProcessingRun;

class OperationalOverview
{
    public function read(): array
    {
        $documents = Document::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->orderBy('status')->pluck('aggregate', 'status')->map(fn ($value) => (int) $value)->all();
        $runs = ProcessingRun::query()->selectRaw('profile, status, count(*) as aggregate')->groupBy('profile', 'status')->get()->mapWithKeys(fn ($run) => [$run->profile->value.' / '.$run->status->value => (int) $run->aggregate])->all();
        $chunks = ProcessingRun::query()->where('status', 'indexed')->whereHas('document', fn ($query) => $query->whereColumn('documents.active_processing_run_id', 'document_processing_runs.id'))->sum('vector_count');
        // Bound the operational duration sample; the full timeline remains in the runs resource.
        $durations = ProcessingRun::query()->where('status', 'indexed')->whereNotNull('started_at')->whereNotNull('indexed_at')->latest('id')->limit(100)->get()->map(fn ($run) => max(0, $run->started_at->diffInMilliseconds($run->indexed_at)));

        return ['documents' => $documents, 'runs' => $runs, 'active_chunks' => (int) $chunks, 'average_duration_ms' => $durations->isEmpty() ? null : $durations->avg()];
    }
}
