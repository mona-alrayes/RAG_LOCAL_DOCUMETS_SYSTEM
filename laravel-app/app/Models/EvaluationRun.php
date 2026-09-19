<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'targets_snapshot' => 'array',
            'config_snapshot' => 'array',
            'metrics' => 'array',
            'latency_summary' => 'array',
            'results' => 'array',

            'started_at' => 'datetime',
            'completed_at' => 'datetime',

            'k' => 'integer',
            'questions_count' => 'integer',
            'completed_questions' => 'integer',
            'successful_questions' => 'integer',
            'failed_questions' => 'integer',
            'answerable_questions' => 'integer',
            'unanswerable_questions' => 'integer',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(EvaluationDataset::class, 'evaluation_dataset_id');
    }

    public function questionResults(): HasMany
    {
        return $this->hasMany(EvaluationQuestionResult::class)
            ->orderBy('sequence');
    }
}
