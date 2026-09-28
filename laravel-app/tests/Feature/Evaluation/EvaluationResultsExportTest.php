<?php

namespace Tests\Feature\Evaluation;

use App\Filament\Pages\EvaluationResults;
use App\Models\EvaluationRun;
use App\Services\Evaluation\EvaluationResultsWorkbook;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class EvaluationResultsExportTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    public function test_completed_evaluation_downloads_a_three_sheet_excel_workbook(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin = $this->admin());

        $run = EvaluationRun::query()->create([
            'created_by' => $admin->id,
            'name' => 'Twenty question benchmark',
            'dataset_version' => 'gold-v2',
            'dataset_sha256' => str_repeat('a', 64),
            'dataset_path' => 'evaluation/test.json',
            'status' => 'completed',
            'k' => 5,
            'questions_count' => 2,
            'completed_questions' => 2,
            'successful_questions' => 2,
            'failed_questions' => 0,
            'answerable_questions' => 2,
            'unanswerable_questions' => 0,
            'targets_snapshot' => [],
            'config_snapshot' => [
                'pipeline' => 'dense_only', 'metric_version' => 'v1', 'k' => 5,
                'rrf_candidate_multiplier' => null, 'rerank_candidate_multiplier' => null,
            ],
            'metrics' => [
                'precision_at_k' => 0.55,
                'recall_at_k' => 0.75,
                'hit_rate_at_k' => 1.0,
                'mrr_at_k' => 0.625,
                'ndcg_at_k' => 0.7,
                'correctness' => 0.8,
                'faithfulness' => 0.9,
                'answer_relevance' => 0.85,
                'abstention_accuracy' => null,
            ],
            'latency_summary' => ['mean_ms' => 1500, 'p95_ms' => 1900],
            'completed_at' => now(),
        ]);

        $run->questionResults()->createMany([
            [
                'question_id' => 'q1', 'sequence' => 1, 'question' => 'السؤال الأول؟',
                'reference_answer' => 'الإجابة المرجعية الأولى', 'generated_answer' => 'إجابة النظام الأولى',
                'status' => 'completed', 'split' => 'held_out', 'category' => 'facts', 'is_answerable' => true,
                'precision_at_k' => 0.5, 'recall_at_k' => 1.0, 'hit_rate_at_k' => 1.0,
                'mrr_at_k' => 1.0, 'ndcg_at_k' => 0.9, 'correctness' => 0.8,
                'faithfulness' => 0.9, 'answer_relevance' => 0.85,
                'retrieval_ms' => 100, 'generation_ms' => 700, 'judge_ms' => 600, 'total_ms' => 1400,
            ],
            [
                'question_id' => 'q2', 'sequence' => 2, 'question' => 'السؤال الثاني؟',
                'reference_answer' => 'الإجابة المرجعية الثانية', 'generated_answer' => 'إجابة النظام الثانية',
                'status' => 'completed', 'split' => 'development', 'category' => 'summary', 'is_answerable' => true,
                'precision_at_k' => 0.6, 'recall_at_k' => 0.5, 'hit_rate_at_k' => 1.0,
                'mrr_at_k' => 0.25, 'ndcg_at_k' => 0.5, 'correctness' => 0.8,
                'faithfulness' => 0.9, 'answer_relevance' => 0.85,
                'retrieval_ms' => 120, 'generation_ms' => 800, 'judge_ms' => 650, 'total_ms' => 1570,
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'evaluation-export-test');

        try {
            app(EvaluationResultsWorkbook::class)->write($run, $path);
            $book = (new Xlsx)->load($path);

            $this->assertSame(['الملخص', 'جودة الاسترجاع', 'جودة الإجابة'], $book->getSheetNames());
            $this->assertSame('Twenty question benchmark', $book->getSheetByName('الملخص')->getCell('B4')->getValue());
            $this->assertSame(0.75, $book->getSheetByName('الملخص')->getCell('B14')->getValue());
            $this->assertSame('السؤال الثاني؟', $book->getSheetByName('جودة الاسترجاع')->getCell('C5')->getValue());
            $this->assertSame('إجابة النظام الأولى', $book->getSheetByName('جودة الإجابة')->getCell('I4')->getValue());
            $this->assertSame(0.9, $book->getSheetByName('جودة الإجابة')->getCell('K4')->getValue());
        } finally {
            @unlink($path);
        }

        Livewire::test(EvaluationResults::class, ['run' => $run->id])
            ->assertSee('تصدير إلى Excel')
            ->call('exportExcel')
            ->assertFileDownloaded("evaluation-results-{$run->id}.xlsx");
    }
}
