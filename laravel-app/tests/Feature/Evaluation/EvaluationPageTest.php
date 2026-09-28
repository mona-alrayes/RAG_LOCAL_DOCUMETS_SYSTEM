<?php

namespace Tests\Feature\Evaluation;

use App\Filament\Pages\EvaluationResults;
use App\Filament\Pages\Evaluations;
use App\Jobs\EvaluateQuestionJob;
use App\Models\EvaluationRun;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AdminFixtures;
use Tests\Support\GoldenDatasetFile;
use Tests\TestCase;

class EvaluationPageTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    public function test_upload_form_dispatches_evaluation_and_redirects_to_results(): void
    {
        Queue::fake();
        Storage::fake('local');

        Filament::setCurrentPanel(
            Filament::getPanel('admin')
        );

        $this->actingAs($this->admin());

        $document = $this->document();

        $document->forceFill([
            'status' => 'ready',
        ])->save();

        $processingRun = $document
            ->processingRuns()
            ->create([
                'profile' => 'cloud',
                'status' => 'indexed',
                'kind' => 'initial',
                'profile_snapshot' => [],
                'stage_timings_ms' => [],
                'total_chunks' => 3,
                'vector_count' => 3,
            ]);

        $document->forceFill([
            'active_processing_run_id' => $processingRun->id,
        ])->save();

        $dataset = [
            'schema_version' => 2,
            'dataset_version' => 'test-v2',
            'examples' => [
                [
                    'question_id' => 'q1',
                    'question' => 'Question?',
                    'reference_answer' => 'Reference answer',
                    'split' => 'held_out',
                    'category' => 'factual',
                    'is_answerable' => true,
                    'evidence' => [
                        [
                            'source' => 'notes.txt',
                            'page' => 1,
                            'section' => 'Section',
                            'evidence_text' => 'Evidence text',
                        ],
                    ],
                ],
            ],
        ];

        $file = GoldenDatasetFile::fromArray(
            $dataset
        );

        Livewire::test(Evaluations::class)
            ->fillForm([
                'name' => 'UI benchmark',
                'document_ids' => [$document->id],
                'pipeline' => 'dense_sparse_rrf_reranker',
                'k' => 5,
                'dataset' => $file,
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(
            'evaluation_runs',
            [
                'name' => 'UI benchmark',
                'status' => 'queued',
            ],
        );

        $this->assertDatabaseHas(
            'evaluation_datasets',
            [
                'dataset_version' => 'test-v2',
                'schema_version' => 2,
                'questions_count' => 1,
            ],
        );

        Queue::assertPushed(
            EvaluateQuestionJob::class
        );

        Livewire::test(
            EvaluationResults::class,
            [
                'run' => EvaluationRun::firstOrFail()->id,
            ],
        )->assertSuccessful();
    }
}
