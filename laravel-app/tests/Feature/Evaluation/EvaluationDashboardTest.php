<?php

namespace Tests\Feature\Evaluation;

use App\Filament\Pages\EvaluationDashboard;
use App\Models\EvaluationRun;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class EvaluationDashboardTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    public function test_dashboard_is_accessible_and_uses_arabic_labels(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);

        Filament::setCurrentPanel(
            Filament::getPanel('admin')
        );

        EvaluationRun::query()->create([
            'created_by' => $admin->id,
            'name' => 'Arabic dashboard test',
            'dataset_version' => 'gold-v2',
            'dataset_sha256' => str_repeat('a', 64),
            'dataset_path' => 'evaluation/test.json',
            'status' => 'completed',
            'k' => 5,
            'questions_count' => 1,
            'completed_questions' => 1,
            'successful_questions' => 1,
            'failed_questions' => 0,
            'answerable_questions' => 1,
            'unanswerable_questions' => 0,
            'targets_snapshot' => [],
            'config_snapshot' => [
                'pipeline' => 'dense_sparse_rrf_reranker',
                'metric_version' => 'binary-chunk-v1',
            ],
            'metrics' => [
                'precision_at_k' => 1.0,
                'recall_at_k' => 1.0,
                'hit_rate_at_k' => 1.0,
                'mrr_at_k' => 1.0,
                'ndcg_at_k' => 1.0,
                'correctness' => 1.0,
                'faithfulness' => 1.0,
                'answer_relevance' => 1.0,
                'abstention_accuracy' => null,
            ],
            'latency_summary' => [
                'retrieval_mean_ms' => 10,
                'generation_mean_ms' => 20,
                'judge_mean_ms' => 30,
                'mean_ms' => 60,
                'p95_ms' => 60,
            ],
        ]);

        Livewire::test(
            EvaluationDashboard::class
        )
            ->assertSuccessful()
            ->assertSee('نظرة عامة')
            ->assertSee('جودة الاسترجاع')
            ->assertSee('جودة الإجابة')
            ->assertSee('الالتزام بالمصادر')
            ->assertSee('زمن الاسترجاع')
            ->assertSee('متوسط الزمن الكلي')
            ->assertSee('الأداء متوازن')
            ->assertSee('مقارنة المسارات');
    }
}
