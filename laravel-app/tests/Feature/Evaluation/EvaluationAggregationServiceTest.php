<?php

namespace Tests\Feature\Evaluation;

use App\Models\EvaluationRun;
use App\Services\Evaluation\EvaluationAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class EvaluationAggregationServiceTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    public function test_abstention_accuracy_averages_boolean_results(): void
    {
        $run = EvaluationRun::create([
            'created_by' => $this->admin()->id,
            'name' => 'Abstention aggregation',
            'dataset_version' => 'test-v1',
            'dataset_sha256' => str_repeat('a', 64),
            'dataset_path' => 'evaluation-datasets/test.json',
            'status' => 'completed',
            'k' => 5,
            'questions_count' => 2,
            'completed_questions' => 2,
            'successful_questions' => 2,
            'failed_questions' => 0,
            'answerable_questions' => 0,
            'unanswerable_questions' => 2,
            'targets_snapshot' => [],
        ]);

        $run->questionResults()->create([
            'question_id' => 'q1',
            'sequence' => 1,
            'question' => 'Unanswerable question 1?',
            'is_answerable' => false,
            'status' => 'completed',
            'abstention_correct' => true,
        ]);

        $run->questionResults()->create([
            'question_id' => 'q2',
            'sequence' => 2,
            'question' => 'Unanswerable question 2?',
            'is_answerable' => false,
            'status' => 'completed',
            'abstention_correct' => false,
        ]);

        $result = app(
            EvaluationAggregationService::class
        )->aggregate($run);

        $this->assertSame(
            0.5,
            $result['metrics']['abstention_accuracy'],
        );
    }
}
