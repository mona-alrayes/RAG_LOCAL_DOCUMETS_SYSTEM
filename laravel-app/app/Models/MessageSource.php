<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'processing_run_id',
    'qdrant_point_id',
    'chunk_index',
    'source_snapshot',
    'relevance_score',
    'reranker_score',
])]
class MessageSource extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'source_snapshot' => 'array',
            'relevance_score' => 'float',
            'reranker_score' => 'float',
        ];
    }

    /**
     * Get the message that owns this source.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Get the processing run from which this source was retrieved.
     */
    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(ProcessingRun::class);
    }
}
