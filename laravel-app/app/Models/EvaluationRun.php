<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['targets_snapshot' => 'array', 'config_snapshot' => 'array', 'metrics' => 'array', 'latency_summary' => 'array', 'results' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'k' => 'integer', 'questions_count' => 'integer', 'completed_questions' => 'integer'];
    }
}
