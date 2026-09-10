<?php

namespace App\Models;

use App\Enums\ProcessingProfile;
use App\Enums\ProcessingRunKind;
use App\Enums\ProcessingRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile',
    'status',
    'kind',
    'profile_snapshot',
    'total_pages',
    'total_chunks',
    'vector_count',
    'vector_dimension',
    'stage_timings_ms',
    'warnings',
    'error_code',
    'failure_reason',
    'qdrant_collection',
    'started_at',
    'indexing_started_at',
    'indexed_at',
    'failed_at',
])]
class ProcessingRun extends Model
{
    protected $table = 'document_processing_runs';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile' => ProcessingProfile::class,
            'status' => ProcessingRunStatus::class,
            'kind' => ProcessingRunKind::class,
            'profile_snapshot' => 'array',
            'stage_timings_ms' => 'array',
            'warnings' => 'array',
            'started_at' => 'datetime',
            'indexing_started_at' => 'datetime',
            'indexed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Get the document that owns this processing run.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
