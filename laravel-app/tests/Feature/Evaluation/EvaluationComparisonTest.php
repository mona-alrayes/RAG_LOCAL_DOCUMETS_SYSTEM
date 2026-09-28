<?php

namespace Tests\Feature\Evaluation;

use App\Filament\Pages\EvaluationResults;
use App\Models\EvaluationRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class EvaluationComparisonTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    private function evaluation(array $overrides = []): EvaluationRun
    {
        return EvaluationRun::create(array_replace([
            'created_by' => auth()->id(), 'name' => 'Comparison fixture', 'dataset_version' => 'v1',
            'dataset_sha256' => hash('sha256', 'fixed dataset'), 'dataset_path' => 'evaluation-datasets/test.json',
            'status' => 'completed', 'k' => 5, 'questions_count' => 1, 'completed_questions' => 1,
            'targets_snapshot' => [['user_id' => 1, 'document_targets' => [['document_id' => 2, 'processing_run_id' => 3, 'processing_profile' => 'cloud']]]],
            'config_snapshot' => ['pipeline' => 'dense_sparse_rrf_reranker', 'metric_version' => 'binary-chunk-v1', 'k' => 5, 'rrf_candidate_multiplier' => 2, 'rerank_candidate_multiplier' => 2, 'cloud_embedding' => 'jina', 'cloud_reranker' => 'jina'],
            'metrics' => ['precision_at_k' => .6, 'recall_at_k' => .6, 'hit_rate_at_k' => 1, 'mrr_at_k' => .5, 'ndcg_at_k' => .6],
            'latency_summary' => ['mean_ms' => 20, 'p95_ms' => 25], 'completed_at' => now(),
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
    }

    public function test_changed_rubric_warns_and_point_checks_are_visible(): void
    {
        // نثبت ظهور دليل المطابقة والتنبيه حتى لا تُفسر فروق المعايير كتحسن إجابات.
        $current = $this->evaluation();
        $current->update(['config_snapshot' => array_replace($current->config_snapshot, ['correctness_rubric_version' => 'correctness-v2'])]);
        $old = $this->evaluation(['config_snapshot' => array_replace($current->config_snapshot, ['correctness_rubric_version' => 'correctness-v1'])]);
        $current->questionResults()->create([
            'question_id' => 'audit', 'sequence' => 1, 'question' => 'When?',
            'reference_answer' => 'Twenty years', 'generated_answer' => '20 years',
            'status' => 'completed', 'is_answerable' => true,
            'judge_details' => ['correctness' => ['checks' => [['reference_quote' => 'Twenty years', 'status' => 'supported', 'answer_quote' => '20 years']]]],
        ]);
        Livewire::test(EvaluationResults::class, ['run' => $current->id])
            ->set('baselineId', $old->id)->assertSuccessful()
            ->assertSee('معيار تقييم صحة الإجابة مختلف بين التشغيلين')
            ->assertSee('مطابقة نقاط الإجابة المرجعية')->assertSee('Twenty years')->assertSee('20 years');
    }

    public function test_compatible_ablation_displays_deltas_and_configuration_differences(): void
    {
        $current = $this->evaluation();
        $baseline = $this->evaluation([
            'name' => 'Dense ablation',
            'config_snapshot' => array_replace(
                $current->config_snapshot,
                [
                    'pipeline' => 'dense_only',
                    'rrf_candidate_multiplier' => null,
                    'rerank_candidate_multiplier' => null,
                ],
            ),
            'metrics' => array_fill_keys(
                array_keys($current->metrics),
                .2,
            ),
        ]);
        Livewire::test(EvaluationResults::class, ['run' => $current->id])
            ->set('baselineId', $baseline->id)->assertHasNoErrors()
            ->assertSee('Dense ablation')->assertSee('+0.4000')->assertSee('dense_only')
            ->assertViewHas('baseline', fn ($run) => $run->id === $baseline->id)
            ->assertViewHas('configurationDifferences', fn ($differences) => $differences['pipeline']['baseline'] === 'dense_only');
    }

    public function test_incompatible_and_missing_baselines_are_excluded_and_rejected(): void
    {
        $current = $this->evaluation();
        $incompatible = [
            ['dataset_sha256' => hash('sha256', 'other')], ['k' => 10], ['targets_snapshot' => []],
            ['config_snapshot' => array_replace($current->config_snapshot, ['metric_version' => 'other'])],
            ['status' => 'running'], ['status' => 'failed'], ['config_snapshot' => null],
        ];
        foreach ($incompatible as $overrides) {
            $candidate = $this->evaluation($overrides);
            Livewire::test(EvaluationResults::class, ['run' => $current->id])
                ->assertViewHas('compatibleRuns', fn ($runs) => ! $runs->contains('id', $candidate->id))
                ->set('baselineId', $candidate->id)->assertHasErrors('baselineId')->assertSet('baselineId', null)
                ->assertViewHas('baseline', null);
        }
        Livewire::test(EvaluationResults::class, ['run' => $current->id])
            ->set('baselineId', 999999)->assertHasErrors('baselineId');
    }

    public function test_comparison_page_requires_admin_and_reauthorizes_updates(): void
    {
        $run = $this->evaluation();
        $page = Livewire::test(EvaluationResults::class, ['run' => $run->id]);
        $this->actingAs(User::factory()->create());
        $page->set('baselineId', $run->id)->assertForbidden();
        Livewire::test(EvaluationResults::class, ['run' => $run->id])->assertForbidden();
    }
}
