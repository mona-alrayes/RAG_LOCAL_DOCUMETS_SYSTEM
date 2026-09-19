<?php

namespace App\Services\Evaluation;

use App\Exceptions\AiServiceException;
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
    private const RUN_LEVEL_FAILURES = [
        'dataset_changed',
        'index_changed',
        'pipeline_changed',
        'local_resources_busy',
        'evaluation_contract_invalid',
    ];

    public function execute(
        int $runId,
        int $index,
    ): void {
        $run = EvaluationRun::findOrFail($runId);

        if (
            ! in_array(
                $run->status,
                ['queued', 'running'],
                true,
            )
            || $run->completed_questions !== $index
        ) {
            return;
        }

        try {
            AdminAccess::authorize(
                User::find($run->created_by)
            );

            $this->assertSnapshots($run);

            [$dataset, $example, $snapshot] =
                $this->loadQuestion(
                    $run,
                    $index,
                );

            $pipeline = $run->config_snapshot['pipeline']
                ?? 'dense_sparse_rrf_reranker';

            $run->update([
                'status' => 'running',
                'started_at' => $run->started_at ?? now(),
            ]);

            try {
                $payload = $this->requestEvaluation(
                    $run,
                    $example,
                    $snapshot,
                    $pipeline,
                );
            } catch (AiServiceException $exception) {
                $this->persistQuestionFailure(
                    $run->id,
                    $index,
                    'evaluation',
                    $exception->errorCode
                        ?? 'ai_service_failed',
                );

                return;
            }

            try {
                $result = app(
                    EvaluationResponse::class
                )->validate(
                    $payload,
                    $snapshot,
                    $run->k,
                    $pipeline,
                    (bool) $example['is_answerable'],
                );
            } catch (AiServiceException) {
                throw new \RuntimeException(
                    'evaluation_contract_invalid'
                );
            }

            $this->assertSnapshots($run);

            if ($result['status'] === 'binding_failed') {
                $this->persistBindingFailure(
                    $run->id,
                    $index,
                    $result,
                );

                return;
            }

            $this->persistCompletedQuestion(
                $run->id,
                $index,
                $result,
            );
        } catch (\Throwable $exception) {
            report($exception);

            $code = in_array(
                $exception->getMessage(),
                self::RUN_LEVEL_FAILURES,
                true,
            )
                ? $exception->getMessage()
                : 'evaluation_failed';

            self::fail(
                $runId,
                $code,
                $index,
            );
        }
    }

    public static function fail(
        int $runId,
        string $code,
        ?int $expectedIndex = null,
    ): void {
        DB::transaction(function () use (
            $runId,
            $code,
            $expectedIndex,
        ): void {
            $run = EvaluationRun::lockForUpdate()
                ->find($runId);

            if (
                ! $run
                || ! in_array(
                    $run->status,
                    ['queued', 'running'],
                    true,
                )
                || (
                    $expectedIndex !== null
                    && $run->completed_questions
                        !== $expectedIndex
                )
            ) {
                return;
            }

            $run->update([
                'status' => 'failed',
                'error_code' => $code,
                'completed_at' => now(),
                'metrics' => null,
                'latency_summary' => null,
            ]);

            AdminAudit::record(
                $run->created_by,
                'evaluation.failed',
                'evaluation_run',
                $run->id,
                'failed',
            );
        });
    }

    private function requestEvaluation(
        EvaluationRun $run,
        array $example,
        array $snapshot,
        string $pipeline,
    ): array {
        $lock = app(
            LocalHeavyResourceLock::class
        );

        $token = null;

        $local = collect(
            $snapshot['document_targets']
        )->contains(
            'processing_profile',
            'hybrid_local',
        );

        if ($local && $lock->enabled()) {
            $token = $lock->acquireWithin();

            if ($token === null) {
                throw new \RuntimeException(
                    'local_resources_busy'
                );
            }
        }

        try {
            return app(
                AiServiceClient::class
            )->evaluateQuestion(
                $snapshot + [
                    'question_id' => $example['question_id'],
                    'question' => $example['question'],
                    'reference_answer' => $example['reference_answer'],
                    'split' => $example['split'],
                    'category' => $example['category'],
                    'is_answerable' => $example['is_answerable'],
                    'evidence' => $example['evidence'],
                    'k' => $run->k,
                    'pipeline' => $pipeline,
                ],
            );
        } finally {
            if ($token !== null) {
                $lock->release($token);
            }
        }
    }

    private function persistCompletedQuestion(
        int $runId,
        int $index,
        array $result,
    ): void {
        DB::transaction(function () use (
            $runId,
            $index,
            $result,
        ): void {
            $run = EvaluationRun::lockForUpdate()
                ->findOrFail($runId);

            if (
                $run->status !== 'running'
                || $run->completed_questions !== $index
            ) {
                return;
            }

            $this->assertSnapshots(
                $run,
                lock: true,
            );

            $this->assertConfigSnapshot(
                $run,
                $result['config_snapshot'],
            );

            $question = $run->questionResults()
                ->where('sequence', $index + 1)
                ->lockForUpdate()
                ->firstOrFail();

            $retrieval = $result[
                'retrieval_metrics'
            ];
            $generation = $result[
                'generation_metrics'
            ];

            $question->fill([
                'generated_answer' => $result['generated_answer'],
                'status' => 'completed',
                'error_stage' => null,
                'error_code' => null,

                'precision_at_k' => $retrieval['precision_at_k'],
                'recall_at_k' => $retrieval['recall_at_k'],
                'hit_rate_at_k' => $retrieval['hit_rate_at_k'],
                'mrr_at_k' => $retrieval['mrr_at_k'],
                'ndcg_at_k' => $retrieval['ndcg_at_k'],

                'correctness' => $this->judgeScore(
                    $generation['correctness'],
                ),
                'faithfulness' => $this->judgeScore(
                    $generation['faithfulness'],
                ),
                'answer_relevance' => $this->judgeScore(
                    $generation['answer_relevance'],
                ),
                'abstention_correct' => $this->abstentionCorrect(
                    $generation['abstention'],
                ),

                'retrieval_ms' => $result['timings_ms']['retrieval_ms'],
                'generation_ms' => $result['timings_ms']['generation_ms'],
                'judge_ms' => $result['timings_ms']['judge_ms'],
                'total_ms' => $result['timings_ms']['total_ms'],

                'relevant_chunks' => $result['binding']['relevant_chunks'],

                // Current FastAPI contract exposes the final
                // production context. Candidate/rerank traces
                // will be populated when tracing is added.
                'retrieved_context' => $result['retrieved'],

                'judge_details' => $generation,
            ]);

            $question->save();

            $run->successful_questions++;
            $this->advanceRun(
                $run,
                $index,
                $result['config_snapshot'],
            );
        });
    }

    private function persistBindingFailure(
        int $runId,
        int $index,
        array $result,
    ): void {
        DB::transaction(function () use (
            $runId,
            $index,
            $result,
        ): void {
            $run = EvaluationRun::lockForUpdate()
                ->findOrFail($runId);

            if (
                $run->status !== 'running'
                || $run->completed_questions !== $index
            ) {
                return;
            }

            $this->assertSnapshots(
                $run,
                lock: true,
            );

            $question = $run->questionResults()
                ->where('sequence', $index + 1)
                ->lockForUpdate()
                ->firstOrFail();

            $question->fill([
                'status' => 'failed',
                'error_stage' => 'binding',
                'error_code' => 'golden_binding_'.
                    $result['binding']['status'],
                'relevant_chunks' => $result['binding']['relevant_chunks'],
                'judge_details' => [
                    'binding' => $result['binding'],
                ],
                'total_ms' => $result['timings_ms']['total_ms'],
            ]);

            $question->save();

            $run->failed_questions++;
            $this->advanceRun(
                $run,
                $index,
                null,
            );
        });
    }

    private function persistQuestionFailure(
        int $runId,
        int $index,
        string $stage,
        string $code,
    ): void {
        DB::transaction(function () use (
            $runId,
            $index,
            $stage,
            $code,
        ): void {
            $run = EvaluationRun::lockForUpdate()
                ->findOrFail($runId);

            if (
                $run->status !== 'running'
                || $run->completed_questions !== $index
            ) {
                return;
            }

            $this->assertSnapshots(
                $run,
                lock: true,
            );

            $question = $run->questionResults()
                ->where('sequence', $index + 1)
                ->lockForUpdate()
                ->firstOrFail();

            $question->fill([
                'status' => 'failed',
                'error_stage' => $stage,
                'error_code' => $code,
            ]);

            $question->save();

            $run->failed_questions++;

            $this->advanceRun(
                $run,
                $index,
                null,
            );
        });
    }

    private function advanceRun(
        EvaluationRun $run,
        int $index,
        ?array $responseConfig,
    ): void {
        $run->completed_questions =
            $index + 1;

        if ($responseConfig !== null) {
            $run->config_snapshot = [
                ...$responseConfig,
                'dataset_schema_version' => 2,
            ];
        }

        if (
            $run->completed_questions
            === $run->questions_count
        ) {
            $aggregate = app(
                EvaluationAggregationService::class
            )->aggregate($run);

            $run->fill([
                'status' => 'completed',
                'completed_at' => now(),
                'metrics' => $aggregate['metrics'],
                'latency_summary' => $aggregate['latency_summary'],
                'error_code' => null,
            ]);

            $run->save();

            AdminAudit::record(
                $run->created_by,
                'evaluation.completed',
                'evaluation_run',
                $run->id,
            );

            return;
        }

        $run->save();

        EvaluateQuestionJob::dispatch(
            $run->id,
            $index + 1,
        )
            ->onQueue(
                DatasetUpload::queue(
                    $run->targets_snapshot
                )
            )
            ->afterCommit();
    }

    private function assertConfigSnapshot(
        EvaluationRun $run,
        array $responseConfig,
    ): void {
        $expected = [
            ...$responseConfig,
            'dataset_schema_version' => 2,
        ];

        if ($run->completed_questions === 0) {
            return;
        }

        $actual = $this->canonicalizeConfigSnapshot(
            $run->config_snapshot,
        );

        $expected = $this->canonicalizeConfigSnapshot(
            $expected,
        );

        if ($actual !== $expected) {
            throw new \RuntimeException(
                'pipeline_changed'
            );
        }
    }

    private function canonicalizeConfigSnapshot(
        array $snapshot,
    ): array {
        ksort($snapshot);

        foreach ($snapshot as $key => $value) {
            if (
                is_float($value)
                && is_finite($value)
                && floor($value) === $value
            ) {
                $snapshot[$key] = (int) $value;
            }
        }

        return $snapshot;
    }

    private function loadQuestion(
        EvaluationRun $run,
        int $index,
    ): array {
        $raw = Storage::disk('local')
            ->get($run->dataset_path);

        if (
            ! hash_equals(
                $run->dataset_sha256,
                hash('sha256', $raw),
            )
        ) {
            throw new \RuntimeException(
                'dataset_changed'
            );
        }

        $dataset = json_decode(
            $raw,
            true,
            64,
            JSON_THROW_ON_ERROR,
        );

        if (
            ($dataset['schema_version'] ?? null) !== 2
            || ! isset(
                $dataset['examples'][$index],
                $run->targets_snapshot[$index],
            )
        ) {
            throw new \RuntimeException(
                'dataset_changed'
            );
        }

        return [
            $dataset,
            $dataset['examples'][$index],
            $run->targets_snapshot[$index],
        ];
    }

    private function judgeScore(
        array $metric,
    ): ?float {
        return $metric['status'] === 'completed'
            ? (float) $metric['score']
            : null;
    }

    private function abstentionCorrect(
        array $metric,
    ): ?bool {
        if ($metric['status'] !== 'completed') {
            return null;
        }

        // Abstention accuracy is intentionally strict:
        // only a full judge score counts as correct.
        return (float) $metric['score'] === 1.0;
    }

    private function assertSnapshots(
        EvaluationRun $run,
        bool $lock = false,
    ): void {
        $ids = collect(
            $run->targets_snapshot
        )
            ->flatMap(
                fn ($snapshot) => array_column(
                    $snapshot['document_targets'],
                    'document_id',
                ),
            )
            ->unique()
            ->sort()
            ->values();

        $documents = Document::with(
            'activeProcessingRun'
        )
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->when(
                $lock,
                fn ($query) => $query->lockForUpdate(),
            )
            ->get()
            ->keyBy('id');

        foreach (
            $run->targets_snapshot as $snapshot
        ) {
            foreach (
                $snapshot['document_targets'] as $target
            ) {
                $document = $documents->get(
                    $target['document_id']
                );

                if (
                    ! $document
                    || (int) $document->user_id
                        !== (int) $snapshot['user_id']
                    || (int) $document
                        ->active_processing_run_id
                        !== (int) $target[
                            'processing_run_id'
                        ]
                    || $document->status->value
                        !== 'ready'
                    || $document
                        ->activeProcessingRun
                        ?->status->value
                        !== 'indexed'
                    || $document
                        ->activeProcessingRun
                        ?->profile->value
                        !== $target[
                            'processing_profile'
                        ]
                ) {
                    throw new \RuntimeException(
                        'index_changed'
                    );
                }
            }
        }
    }
}
