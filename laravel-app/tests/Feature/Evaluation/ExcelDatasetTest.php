<?php

namespace Tests\Feature\Evaluation;

use App\Filament\Pages\Evaluations;
use App\Services\Evaluation\DatasetUpload;
use App\Services\Evaluation\ExcelDataset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\AdminFixtures;
use Tests\TestCase;

class ExcelDatasetTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function workbook(
        array $questions = [
            [
                'q1',
                'ما محتوى الوثيقة؟',
                'إجابة مرجعية',
                'held_out',
                'factual',
                1,
            ],
        ],
        array $evidence = [
            [
                'q1',
                'notes.txt',
                1,
                'Section',
                'Evidence text',
            ],
        ],
    ): UploadedFile {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        $sheets = [
            'Dataset' => [
                ['dataset_version'],
                ['excel-test-v2'],
            ],
            'Questions' => [
                [
                    'question_id',
                    'question',
                    'reference_answer',
                    'split',
                    'category',
                    'is_answerable',
                ],
                ...$questions,
            ],
            'Evidence' => [
                [
                    'question_id',
                    'source',
                    'page',
                    'section',
                    'evidence_text',
                ],
                ...$evidence,
            ],
        ];

        foreach ($sheets as $name => $rows) {
            $book->createSheet()
                ->setTitle($name)
                ->fromArray(
                    $rows,
                    null,
                    'A1',
                    true,
                );
        }

        $path = tempnam(
            sys_get_temp_dir(),
            'xlsx-test'
        );

        $this->paths[] = $path;

        (new Xlsx($book))->save($path);

        $book->disconnectWorksheets();

        return UploadedFile::fake()
            ->createWithContent(
                'dataset.xlsx',
                file_get_contents($path),
            );
    }

    public function test_excel_upload_uses_selected_corpus_and_persists_canonical_v2_json(): void
    {
        Queue::fake();
        Storage::fake('local');

        [$document] = $this->indexedDocument();

        $evaluation = app(
            DatasetUpload::class
        )->create(
            $this->admin(),
            'Excel test',
            $this->workbook(),
            5,
            'dense_sparse_rrf_reranker',
            [$document->id],
        );

        $stored = json_decode(
            Storage::disk('local')->get(
                $evaluation->dataset_path
            ),
            true,
        );

        $example = $stored['examples'][0];

        $this->assertSame(
            2,
            $stored['schema_version'],
        );

        $this->assertSame(
            'q1',
            $example['question_id'],
        );

        $this->assertSame(
            'إجابة مرجعية',
            $example['reference_answer'],
        );

        $this->assertTrue(
            $example['is_answerable'],
        );

        $this->assertSame(
            'Evidence text',
            $example['evidence'][0][
                'evidence_text'
            ],
        );

        $this->assertArrayNotHasKey(
            'document_ids',
            $example,
        );

        $this->assertArrayNotHasKey(
            'relevant_chunks',
            $example,
        );

        $this->assertSame(
            'queued',
            $evaluation->status,
        );

        $this->assertSame(
            $document->id,
            $evaluation->targets_snapshot[0][
                'document_targets'
            ][0]['document_id'],
        );
    }

    public function test_excel_file_passes_actual_filament_upload_form(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->actingAs($this->admin());

        Filament::setCurrentPanel(
            Filament::getPanel('admin')
        );

        [$document] = $this->indexedDocument();

        Livewire::test(Evaluations::class)
            ->fillForm([
                'name' => 'Excel via Filament',
                'document_ids' => [$document->id],
                'pipeline' => 'dense_sparse_rrf_reranker',
                'k' => 5,
                'dataset' => $this->workbook(),
            ])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas(
            'evaluation_runs',
            [
                'name' => 'Excel via Filament',
                'status' => 'queued',
            ],
        );
    }

    public function test_json_golden_dataset_upload_is_rejected(): void
    {
        Queue::fake();
        Storage::fake('local');

        [$document] = $this->indexedDocument();

        $this->expectException(
            ValidationException::class
        );

        app(DatasetUpload::class)->create(
            $this->admin(),
            'JSON is not supported',
            UploadedFile::fake()->createWithContent(
                'dataset.json',
                '{}',
            ),
            5,
            'dense_sparse_rrf_reranker',
            [$document->id],
        );
    }

    public function test_formulas_are_rejected_without_evaluating_them(): void
    {
        $file = $this->workbook(
            questions: [
                [
                    'q1',
                    '=1+1',
                    'Reference',
                    'held_out',
                    'factual',
                    1,
                ],
            ],
        );

        $this->expectException(
            ValidationException::class
        );

        app(ExcelDataset::class)->read($file);
    }

    public function test_orphan_evidence_and_duplicate_question_ids_are_rejected(): void
    {
        $invalidFiles = [
            $this->workbook(
                evidence: [
                    [
                        'unknown',
                        'notes.txt',
                        1,
                        'Section',
                        'Evidence',
                    ],
                ],
            ),

            $this->workbook(
                questions: [
                    [
                        'q1',
                        'First',
                        'A',
                        'held_out',
                        'factual',
                        1,
                    ],
                    [
                        'q1',
                        'Second',
                        'B',
                        'held_out',
                        'factual',
                        1,
                    ],
                ],
            ),
        ];

        foreach ($invalidFiles as $file) {
            try {
                app(ExcelDataset::class)->read(
                    $file
                );

                $this->fail(
                    'Invalid workbook accepted'
                );
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_template_can_be_read_and_uses_human_authored_v2_schema(): void
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'template'
        );

        $this->paths[] = $path;

        app(ExcelDataset::class)
            ->writeTemplate($path);

        $book = (new XlsxReader)->load($path);

        $instructions = $book->getSheetByName(
            'Instructions'
        );

        $this->assertNotNull($instructions);

        $rows = $instructions->toArray(
            null,
            false,
            false,
            false,
        );

        $this->assertSame(
            [
                'الورقة',
                'الحقل',
                'الحالة',
                'التعليمات',
                'مثال',
            ],
            $rows[0],
        );

        $statuses = array_column(
            array_slice($rows, 1),
            2,
        );

        $this->assertContains('مطلوب', $statuses);
        $this->assertContains('مشروط', $statuses);
        $this->assertContains('اختياري', $statuses);

        $split = collect($rows)->first(
            fn (array $row) => (
                ($row[1] ?? null) === 'split'
            )
        );

        $this->assertNotNull($split);
        $this->assertSame(
            'اختياري',
            $split[2],
        );
        $this->assertStringContainsString(
            'held_out',
            $split[3],
        );

        $book->disconnectWorksheets();

        $data = app(ExcelDataset::class)
            ->read(
                new UploadedFile(
                    $path,
                    'template.xlsx',
                    null,
                    null,
                    true,
                )
            );

        $this->assertSame(
            2,
            $data['schema_version'],
        );

        $this->assertNotEmpty(
            $data['dataset_version'],
        );

        $this->assertNotEmpty(
            $data['examples'],
        );

        foreach ($data['examples'] as $example) {
            $this->assertArrayHasKey(
                'question_id',
                $example,
            );

            $this->assertArrayHasKey(
                'reference_answer',
                $example,
            );

            $this->assertArrayHasKey(
                'is_answerable',
                $example,
            );

            $this->assertArrayHasKey(
                'evidence',
                $example,
            );

            $this->assertArrayNotHasKey(
                'document_ids',
                $example,
            );

            $this->assertArrayNotHasKey(
                'relevant_chunks',
                $example,
            );
        }

        $unanswerable = collect(
            $data['examples']
        )->firstWhere(
            'is_answerable',
            false,
        );

        $this->assertNotNull(
            $unanswerable
        );

        $this->assertSame(
            [],
            $unanswerable['evidence'],
        );
    }

    private function indexedDocument(): array
    {
        $document = $this->document();

        $document->forceFill([
            'status' => 'ready',
        ])->save();

        $run = $document
            ->processingRuns()
            ->create([
                'profile' => 'cloud',
                'status' => 'indexed',
                'kind' => 'initial',
                'total_chunks' => 1,
                'vector_count' => 1,
                'profile_snapshot' => [],
                'stage_timings_ms' => [],
            ]);

        $document->forceFill([
            'active_processing_run_id' => $run->id,
        ])->save();

        return [$document, $run];
    }
}
