<?php

namespace App\Services\Evaluation;

use App\Jobs\EvaluateQuestionJob;
use App\Models\Document;
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
    public function create(User $actor, string $name, UploadedFile $file, int $k): EvaluationRun
    {
        AdminAccess::authorize($actor);
        Validator::make(['name' => $name, 'file' => $file, 'k' => $k], ['name' => 'required|string|max:255', 'file' => 'required|file|max:1024', 'k' => 'integer|min:1|max:20'])->validate();
        try {
            $dataset = strtolower($file->getClientOriginalExtension()) === 'xlsx'
                ? app(ExcelDataset::class)->read($file)
                : json_decode($file->get(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['data.dataset' => 'Upload a valid UTF-8 JSON dataset.']);
        }
        if (! is_array($dataset)) {
            throw ValidationException::withMessages(['data.dataset' => 'The dataset must be a JSON object.']);
        }
        Validator::make(['dataset' => $dataset], [
            'dataset' => 'required|array:schema_version,dataset_version,examples',
            'dataset.schema_version' => 'required|integer|in:1',
            'dataset.dataset_version' => 'required|string|max:100',
            'dataset.examples' => 'required|array|min:1|max:100|list',
            'dataset.examples.*' => 'required|array:question,document_ids,relevant_chunks,expected_answer',
            'dataset.examples.*.question' => 'required|string|max:10000',
            'dataset.examples.*.expected_answer' => 'sometimes|nullable|string|max:20000',
            'dataset.examples.*.document_ids' => 'required|array|min:1|max:20|list',
            'dataset.examples.*.document_ids.*' => 'required|integer|min:1',
            'dataset.examples.*.relevant_chunks' => 'required|array|min:1|max:1000|list',
            'dataset.examples.*.relevant_chunks.*' => 'required|array:document_id,chunk_index',
            'dataset.examples.*.relevant_chunks.*.document_id' => 'required|integer|min:1',
            'dataset.examples.*.relevant_chunks.*.chunk_index' => 'required|integer|min:0',
        ])->validate();
        $snapshots = [];
        foreach ($dataset['examples'] as $index => $example) {
            $ids = array_map('intval', $example['document_ids']);
            $documents = Document::with('activeProcessingRun')->whereIn('id', $ids)->get()->keyBy('id');
            if (count($ids) !== count(array_unique($ids)) || $documents->count() !== count($ids) || $documents->pluck('user_id')->unique()->count() !== 1) {
                $this->invalid($index, 'Choose unique, existing documents belonging to one owner per question.');
            }
            $targets = [];
            foreach ($ids as $id) {
                $document = $documents[$id];
                $run = $document->activeProcessingRun;
                if ($document->status->value !== 'ready' || ! $run || $run->document_id !== $document->id || $run->status->value !== 'indexed' || $run->total_chunks < 1 || $run->vector_count !== $run->total_chunks) {
                    $this->invalid($index, 'Every document must have a ready, fully indexed active run.');
                }
                $targets[] = ['document_id' => $id, 'processing_run_id' => $run->id, 'processing_profile' => $run->profile->value];
            }
            $seen = [];
            foreach ($example['relevant_chunks'] as $label) {
                $id = (int) $label['document_id'];
                $chunk = (int) $label['chunk_index'];
                $key = "$id:$chunk";
                if (! isset($documents[$id]) || $chunk >= $documents[$id]->activeProcessingRun->total_chunks || isset($seen[$key])) {
                    $this->invalid($index, 'Relevant chunks must be unique and inside the selected indexed documents.');
                }
                $seen[$key] = true;
            }
            $snapshots[] = ['user_id' => (int) $documents->first()->user_id, 'document_targets' => $targets];
        }
        $encoded = json_encode($dataset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $path = 'evaluation-datasets/'.Str::uuid().'.json';
        if (! Storage::disk('local')->put($path, $encoded)) {
            throw new \RuntimeException('Dataset storage failed.');
        }
        try {
            return DB::transaction(function () use ($actor, $name, $dataset, $encoded, $path, $k, $snapshots) {
                $run = EvaluationRun::create(['created_by' => $actor->id, 'name' => $name, 'dataset_version' => $dataset['dataset_version'], 'dataset_sha256' => hash('sha256', $encoded), 'dataset_path' => $path, 'k' => $k, 'questions_count' => count($dataset['examples']), 'targets_snapshot' => $snapshots, 'status' => 'queued']);
                AdminAudit::record($actor->id, 'evaluation.upload', 'evaluation_run', $run->id);
                EvaluateQuestionJob::dispatch($run->id, 0)->onQueue(self::queue($snapshots))->afterCommit();

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

    private function invalid(int $index, string $message): never
    {
        throw ValidationException::withMessages(['data.dataset' => 'Example '.($index + 1).': '.$message]);
    }
}
