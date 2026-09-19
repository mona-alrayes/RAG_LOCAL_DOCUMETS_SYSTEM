<?php

namespace App\Services\Evaluation;

use App\Models\EvaluationRun;
use Illuminate\Support\Collection;

class EvaluationAggregationService
{
    public function aggregate(EvaluationRun $run): array
    {
        $results = $run->questionResults()
            ->orderBy('sequence')
            ->get();

        $completed = $results->where('status', 'completed')->values();

        $overall = $this->metricBlock($completed);

        return [
            'metrics' => [
                ...$overall,
                'overall' => $overall,
                'by_split' => $this->groupedMetrics(
                    $completed,
                    'split',
                ),
                'by_category' => $this->groupedMetrics(
                    $completed,
                    'category',
                ),
            ],
            'latency_summary' => $this->latencySummary(
                $completed,
            ),
        ];
    }

    private function metricBlock(Collection $results): array
    {
        return [
            'precision_at_k' => $this->average(
                $results,
                'precision_at_k',
            ),
            'recall_at_k' => $this->average(
                $results,
                'recall_at_k',
            ),
            'hit_rate_at_k' => $this->average(
                $results,
                'hit_rate_at_k',
            ),
            'mrr_at_k' => $this->average(
                $results,
                'mrr_at_k',
            ),
            'ndcg_at_k' => $this->average(
                $results,
                'ndcg_at_k',
            ),
            'correctness' => $this->average(
                $results,
                'correctness',
            ),
            'faithfulness' => $this->average(
                $results,
                'faithfulness',
            ),
            'answer_relevance' => $this->average(
                $results,
                'answer_relevance',
            ),
            'abstention_accuracy' => $this->booleanAverage(
                $results,
                'abstention_correct',
            ),
            'questions' => $results->count(),
        ];
    }

    private function groupedMetrics(
        Collection $results,
        string $field,
    ): array {
        return $results
            ->filter(
                fn ($result) => $result->{$field} !== null
                    && $result->{$field} !== '',
            )
            ->groupBy($field)
            ->map(
                fn (Collection $group) => $this->metricBlock(
                    $group->values(),
                ),
            )
            ->all();
    }

    private function latencySummary(
        Collection $results,
    ): array {
        $total = $this->numericValues(
            $results,
            'total_ms',
        )->sort()->values();

        return [
            // Preserve the existing dashboard contract.
            'mean_ms' => $total->isEmpty()
                ? null
                : $total->avg(),
            'p95_ms' => $this->percentile95($total),

            'retrieval_mean_ms' => $this->average(
                $results,
                'retrieval_ms',
            ),
            'generation_mean_ms' => $this->average(
                $results,
                'generation_ms',
            ),
            'judge_mean_ms' => $this->average(
                $results,
                'judge_ms',
            ),
            'total_mean_ms' => $total->isEmpty()
                ? null
                : $total->avg(),
            'completed_questions' => $results->count(),
        ];
    }

    private function booleanAverage(
        Collection $results,
        string $field,
    ): ?float {
        $values = $results
            ->pluck($field)
            ->filter(
                fn ($value) => $value !== null,
            )
            ->map(
                fn ($value) => $value ? 1.0 : 0.0,
            );

        return $values->isEmpty()
            ? null
            : (float) $values->avg();
    }

    private function average(
        Collection $results,
        string $field,
    ): ?float {
        $values = $this->numericValues(
            $results,
            $field,
        );

        return $values->isEmpty()
            ? null
            : (float) $values->avg();
    }

    private function numericValues(
        Collection $results,
        string $field,
    ): Collection {
        return $results
            ->pluck($field)
            ->filter(
                fn ($value) => $value !== null
                    && is_numeric($value),
            )
            ->map(fn ($value) => (float) $value)
            ->values();
    }

    private function percentile95(
        Collection $sorted,
    ): ?float {
        if ($sorted->isEmpty()) {
            return null;
        }

        $index = max(
            0,
            (int) ceil($sorted->count() * 0.95) - 1,
        );

        return (float) $sorted[$index];
    }
}
