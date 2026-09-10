<?php

namespace App\Services\Evaluation;

use App\Jobs\EvaluateQuestionJob;
use App\Models\Document;
use App\Models\EvaluationRun;
use App\Models\User;
use App\Services\Admin\AdminAccess;
use App\Services\Admin\AdminAudit;
use App\Services\Ai\AiServiceClient;
use App\Services\Infrastructure\LocalHeavyResourceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EvaluationRunner
{
    public function execute(int $runId, int $index): void
    {
        $run = EvaluationRun::findOrFail($runId);
        if (! in_array($run->status, ['queued', 'running'], true) || $run->completed_questions !== $index) {
            return;
        }
        try {
            AdminAccess::authorize(User::find($run->created_by));
            $this->assertSnapshots($run);
            $raw = Storage::disk('local')->get($run->dataset_path);
            if (! hash_equals($run->dataset_sha256, hash('sha256', $raw))) {
                throw new \RuntimeException('dataset_changed');
            }
            $dataset = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            $example = $dataset['examples'][$index];
            $snapshot = $run->targets_snapshot[$index];
            $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now()]);
            $lock = app(LocalHeavyResourceLock::class);
            $token = null;
            $local = collect($snapshot['document_targets'])->contains('processing_profile', 'hybrid_local');
            if ($local && $lock->enabled()) {
                $token = $lock->acquireWithin();
                if ($token === null) {
                    throw new \RuntimeException('local_resources_busy');
                }
            }
            try {
                $payload = app(AiServiceClient::class)->evaluateQuestion($snapshot + ['question' => $example['question'], 'relevant_chunks' => $example['relevant_chunks'], 'k' => $run->k]);
            } finally {
                if ($token !== null) {
                    $lock->release($token);
                }
            }
            $result = app(EvaluationResponse::class)->validate($payload, $snapshot, $run->k);
            $this->assertSnapshots($run);
            DB::transaction(function () use ($run, $index, $result): void {
                $locked = EvaluationRun::lockForUpdate()->findOrFail($run->id);
                if ($locked->status !== 'running' || $locked->completed_questions !== $index) {
                    return;
                }
                $this->assertSnapshots($locked, lock: true);
                if ($locked->config_snapshot !== null && $locked->config_snapshot != $result['config_snapshot']) {
                    throw new \RuntimeException('pipeline_changed');
                }
                $results = $locked->results ?? [];
                $results[] = ['example' => $index + 1, 'metrics' => $result['metrics'], 'retrieved' => $result['retrieved'], 'latency_ms' => $result['latency_ms']];
                $locked->fill(['results' => $results, 'completed_questions' => $index + 1, 'config_snapshot' => $result['config_snapshot']]);
                if ($index + 1 === $locked->questions_count) {
                    $metrics = [];
                    foreach (EvaluationResponse::METRICS as $key) {
                        $metrics[$key] = collect($results)->avg('metrics.'.$key);
                    }
                    $latencies = collect($results)->pluck('latency_ms')->sort()->values();
                    $locked->fill(['status' => 'completed', 'completed_at' => now(), 'metrics' => $metrics, 'latency_summary' => ['mean_ms' => $latencies->avg(), 'p95_ms' => $latencies[(int) ceil($latencies->count() * .95) - 1]]]);
                    AdminAudit::record($locked->created_by, 'evaluation.completed', 'evaluation_run', $locked->id);
                } else {
                    EvaluateQuestionJob::dispatch($locked->id, $index + 1)->onQueue(DatasetUpload::queue($locked->targets_snapshot))->afterCommit();
                }
                $locked->save();
            });
        } catch (\Throwable $exception) {
            $code = in_array($exception->getMessage(), ['dataset_changed', 'index_changed', 'pipeline_changed', 'local_resources_busy'], true) ? $exception->getMessage() : 'evaluation_failed';
            self::fail($runId, $code, $index);
        }
    }

    public static function fail(int $runId, string $code, ?int $expectedIndex = null): void
    {
        DB::transaction(function () use ($runId, $code, $expectedIndex): void {
            $run = EvaluationRun::lockForUpdate()->find($runId);
            if ($run && in_array($run->status, ['queued', 'running'], true)
                && ($expectedIndex === null || $run->completed_questions === $expectedIndex)) {
                $run->update(['status' => 'failed', 'error_code' => $code, 'completed_at' => now(), 'metrics' => null, 'latency_summary' => null]);
                AdminAudit::record($run->created_by, 'evaluation.failed', 'evaluation_run', $run->id, 'failed');
            }
        });
    }

    private function assertSnapshots(EvaluationRun $run, bool $lock = false): void
    {
        $ids = collect($run->targets_snapshot)->flatMap(fn ($snapshot) => array_column($snapshot['document_targets'], 'document_id'))->unique()->sort()->values();
        $documents = Document::with('activeProcessingRun')->whereIn('id', $ids)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        foreach ($run->targets_snapshot as $snapshot) {
            foreach ($snapshot['document_targets'] as $target) {
                $doc = $documents->get($target['document_id']);
                if (! $doc || (int) $doc->user_id !== (int) $snapshot['user_id'] || (int) $doc->active_processing_run_id !== (int) $target['processing_run_id'] || $doc->status->value !== 'ready' || $doc->activeProcessingRun?->status->value !== 'indexed' || $doc->activeProcessingRun?->profile->value !== $target['processing_profile']) {
                    throw new \RuntimeException('index_changed');
                }
            }
        }
    }
}
