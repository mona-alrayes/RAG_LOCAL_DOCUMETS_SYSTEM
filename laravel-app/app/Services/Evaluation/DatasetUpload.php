<?php

namespace App\Services\Evaluation;

use App\Jobs\EvaluateQuestionJob;
use App\Models\Document;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\User;
use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DatasetUpload
{
    public function create(
        User $actor,
        string $name,
        UploadedFile $file,
        int $k,
        string $pipeline = 'dense_sparse_rrf_reranker',
        array $documentIds = [],
    ): EvaluationRun {
        AdminAccess::authorize($actor);

        Validator::make(
            compact('name', 'file', 'k', 'pipeline', 'documentIds'),
            [
                'name' => 'required|string|max:255',
                'file' => 'required|file|max:1024',
                'k' => 'required|integer|min:1|max:20',
                'pipeline' => 'required|in:dense_only,dense_sparse_rrf,dense_sparse_rrf_reranker',
                'documentIds' => 'required|array|min:1|max:20',
                'documentIds.*' => 'required|integer|min:1',
            ],
        )->validate();

        if (
            strtolower(
                $file->getClientOriginalExtension()
            ) !== 'xlsx'
        ) {
            $this->invalid(
                'ارفع ملف Excel بصيغة xlsx.'
            );
        }

        $dataset = app(
            ExcelDataset::class
        )->read($file);

        $this->validateDataset($dataset);

        $targets = $this->trustedTargets($documentIds);
        $userId = (int) $targets['user_id'];
        $documentTargets = $targets['document_targets'];

        $snapshots = array_map(
            fn () => [
                'user_id' => $userId,
                'document_targets' => $documentTargets,
            ],
            $dataset['examples'],
        );

        $encoded = json_encode(
            $dataset,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $sha256 = hash('sha256', $encoded);
        $path = 'evaluation-datasets/'.Str::uuid().'.json';

        if (! Storage::disk('local')->put($path, $encoded)) {
            throw new \RuntimeException('Dataset storage failed.');
        }

        try {
            return DB::transaction(function () use (
                $actor,
                $name,
                $dataset,
                $sha256,
                $path,
                $k,
                $snapshots,
                $pipeline,
            ) {
                $evaluationDataset = EvaluationDataset::create([
                    'created_by' => $actor->id,
                    'name' => $name,
                    'dataset_version' => $dataset['dataset_version'],
                    'schema_version' => 2,
                    'sha256' => $sha256,
                    'file_path' => $path,
                    'questions_count' => count($dataset['examples']),
                ]);

                $answerable = collect($dataset['examples'])
                    ->where('is_answerable', true)
                    ->count();

                $run = EvaluationRun::create([
                    'created_by' => $actor->id,
                    'evaluation_dataset_id' => $evaluationDataset->id,
                    'name' => $name,
                    'dataset_version' => $dataset['dataset_version'],
                    'dataset_sha256' => $sha256,
                    'dataset_path' => $path,
                    'k' => $k,
                    'questions_count' => count($dataset['examples']),
                    'completed_questions' => 0,
                    'successful_questions' => 0,
                    'failed_questions' => 0,
                    'answerable_questions' => $answerable,
                    'unanswerable_questions' => count($dataset['examples']) - $answerable,
                    'targets_snapshot' => $snapshots,
                    'status' => 'queued',
                    'config_snapshot' => [
                        'pipeline' => $pipeline,
                        'dataset_schema_version' => 2,
                    ],
                ]);

                foreach ($dataset['examples'] as $index => $example) {
                    $run->questionResults()->create([
                        'question_id' => $example['question_id'],
                        'sequence' => $index + 1,
                        'question' => $example['question'],
                        'reference_answer' => $example['reference_answer'],
                        'split' => $example['split'],
                        'category' => $example['category'],
                        'is_answerable' => $example['is_answerable'],
                        'status' => 'pending',
                        'golden_evidence' => $example['evidence'],
                    ]);
                }

                AdminAudit::record(
                    $actor->id,
                    'evaluation.upload',
                    'evaluation_run',
                    $run->id,
                );

                EvaluateQuestionJob::dispatch($run->id, 0)
                    ->onQueue(self::queue($snapshots))
                    ->afterCommit();

                return $run;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    public static function queue(array $snapshots): string
    {
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['document_targets'] as $target) {
                if ($target['processing_profile'] === 'hybrid_local') {
                    return config('queue.processing.local_queue', 'ai-local');
                }
            }
        }

        return config('queue.processing.cloud_queue', 'default');
    }

    private function validateDataset(array $dataset): void
    {
        Validator::make(
            ['dataset' => $dataset],
            [
                'dataset' => 'required|array:schema_version,dataset_version,examples',
                'dataset.schema_version' => 'required|integer|in:2',
                'dataset.dataset_version' => 'required|string|max:100',
                'dataset.examples' => 'required|array|min:1|max:100|list',

                'dataset.examples.*' => 'required|array:question_id,question,reference_answer,split,category,is_answerable,evidence',
                'dataset.examples.*.question_id' => 'required|string|max:100',
                'dataset.examples.*.question' => 'required|string|max:10000',
                'dataset.examples.*.reference_answer' => 'nullable|string|max:20000',
                'dataset.examples.*.split' => 'required|in:development,held_out',
                'dataset.examples.*.category' => 'nullable|string|max:100',
                'dataset.examples.*.is_answerable' => 'required|boolean',
                'dataset.examples.*.evidence' => 'present|array|max:50|list',

                'dataset.examples.*.evidence.*' => 'required|array:source,page,section,evidence_text',
                'dataset.examples.*.evidence.*.source' => 'nullable|string|max:255',
                'dataset.examples.*.evidence.*.page' => 'nullable|integer|min:1',
                'dataset.examples.*.evidence.*.section' => 'nullable|string|max:255',
                'dataset.examples.*.evidence.*.evidence_text' => 'required|string|max:20000',
            ],
        )->validate();

        $seen = [];

        foreach ($dataset['examples'] as $index => $example) {
            $id = trim($example['question_id']);

            if ($id === '' || isset($seen[$id])) {
                $this->invalid(
                    'Example '.($index + 1).': question_id must be unique and non-empty.'
                );
            }

            $seen[$id] = true;

            if (! trim($example['question'])) {
                $this->invalid(
                    'Example '.($index + 1).': question cannot be blank.'
                );
            }

            if ($example['is_answerable']) {
                if (count($example['evidence']) < 1) {
                    $this->invalid(
                        'Example '.($index + 1).': answerable questions require Golden Evidence.'
                    );
                }

                if (
                    ! is_string($example['reference_answer'])
                    || trim($example['reference_answer']) === ''
                ) {
                    $this->invalid(
                        'Example '.($index + 1).': answerable questions require a reference_answer.'
                    );
                }
            } elseif (count($example['evidence']) !== 0) {
                $this->invalid(
                    'Example '.($index + 1).': unanswerable questions must have empty evidence.'
                );
            }
        }
    }

    private function trustedTargets(array $documentIds): array
    {
        $ids = array_values(array_map('intval', $documentIds));

        if (count($ids) !== count(array_unique($ids))) {
            $this->invalid('Choose each evaluation document only once.');
        }

        $documents = Document::with('activeProcessingRun')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($documents->count() !== count($ids)) {
            $this->invalid('One or more selected documents do not exist.');
        }

        if ($documents->pluck('user_id')->unique()->count() !== 1) {
            $this->invalid('All selected evaluation documents must belong to one owner.');
        }

        $targets = [];

        foreach ($ids as $id) {
            $document = $documents[$id];
            $run = $document->activeProcessingRun;

            if (
                $document->status->value !== 'ready'
                || ! $run
                || $run->document_id !== $document->id
                || $run->status->value !== 'indexed'
                || $run->total_chunks < 1
                || $run->vector_count !== $run->total_chunks
            ) {
                $this->invalid(
                    "Document {$id} must have a ready, fully indexed active processing run."
                );
            }

            $targets[] = [
                'document_id' => $document->id,
                'processing_run_id' => $run->id,
                'processing_profile' => $run->profile->value,
            ];
        }

        return [
            'user_id' => (int) $documents->first()->user_id,
            'document_targets' => $targets,
        ];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages([
            'data.dataset' => $message,
        ]);
    }
}
