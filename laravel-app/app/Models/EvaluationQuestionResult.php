<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluationQuestionResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_answerable' => 'boolean',
            'abstention_correct' => 'boolean',

            'precision_at_k' => 'float',
            'recall_at_k' => 'float',
            'hit_rate_at_k' => 'float',
            'mrr_at_k' => 'float',
            'ndcg_at_k' => 'float',

            'correctness' => 'float',
            'faithfulness' => 'float',
            'answer_relevance' => 'float',

            'retrieval_ms' => 'float',
            'generation_ms' => 'float',
            'judge_ms' => 'float',
            'total_ms' => 'float',

            'golden_evidence' => 'array',
            'relevant_chunks' => 'array',
            'retrieved_candidates' => 'array',
            'reranked_chunks' => 'array',
            'retrieved_context' => 'array',
            'judge_details' => 'array',
        ];
    }

    public function evaluationRun(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class);
    }
}
