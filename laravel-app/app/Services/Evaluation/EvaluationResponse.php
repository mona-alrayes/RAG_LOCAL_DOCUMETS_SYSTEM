<?php

namespace App\Services\Evaluation;

use App\Exceptions\AiServiceException;
use Illuminate\Support\Facades\Validator;

class EvaluationResponse
{
    public const RETRIEVAL_METRICS = [
        'precision_at_k',
        'recall_at_k',
        'hit_rate_at_k',
        'mrr_at_k',
        'ndcg_at_k',
    ];

    public const METRICS = self::RETRIEVAL_METRICS;

    public const JUDGE_METRICS = [
        'correctness',
        'faithfulness',
        'answer_relevance',
        'abstention',
    ];

    public const CONFIG = [
        'pipeline',
        'metric_version',
        'k',
        'rrf_candidate_multiplier',
        'rerank_candidate_multiplier',
        'cloud_embedding',
        'cloud_reranker',
        'local_embedding',
        'local_reranker',
        'answer_provider',
        'answer_model',
        'answer_temperature',
        'answer_prompt_version',
        'answer_prompt_sha256',
        'judge_provider',
        'judge_model',
        'judge_temperature',
        'correctness_rubric_version',
        'faithfulness_rubric_version',
        'answer_relevance_rubric_version',
        'abstention_rubric_version',
    ];

    public function validate(
        array $payload,
        array $snapshot,
        int $k,
        string $pipeline = 'dense_sparse_rrf_reranker',
        bool $isAnswerable = true,
    ): array {
        $rules = [
            'status' => 'required|in:completed,binding_failed',

            'binding' => 'required|array',
            'binding.status' => 'required|in:bound,not_applicable,needs_review,unbound',
            'binding.relevant_chunks' => 'present|array',
            'binding.evidence' => 'present|array',

            'retrieval_metrics' => 'required|array',

            'generated_answer' => 'nullable|string|max:100000',

            'generation_metrics' => 'required|array',

            'retrieved' => "present|array|max:$k",

            'timings_ms' => 'required|array',
            'timings_ms.retrieval_ms' => 'nullable|numeric|min:0',
            'timings_ms.generation_ms' => 'nullable|numeric|min:0',
            'timings_ms.judge_ms' => 'nullable|numeric|min:0',
            'timings_ms.total_ms' => 'required|numeric|min:0',

            'config_snapshot' => 'required|array',
            'config_snapshot.pipeline' => 'required|in:'.$pipeline,
            'config_snapshot.metric_version' => 'required|in:binary-chunk-v1',
            'config_snapshot.k' => 'required|integer|in:'.$k,
            'config_snapshot.rrf_candidate_multiplier' => 'present|nullable|integer|min:1',
            'config_snapshot.rerank_candidate_multiplier' => 'present|nullable|integer|min:1',

            'config_snapshot.cloud_embedding' => 'required|string|max:255',
            'config_snapshot.cloud_reranker' => 'required|string|max:255',
            'config_snapshot.local_embedding' => 'required|string|max:255',
            'config_snapshot.local_reranker' => 'required|string|max:255',

            'config_snapshot.answer_provider' => 'nullable|string|max:255',
            'config_snapshot.answer_model' => 'nullable|string|max:255',
            'config_snapshot.answer_temperature' => 'required|numeric|min:0|max:2',
            'config_snapshot.answer_prompt_version' => 'required|in:rag-answer-v1',
            'config_snapshot.answer_prompt_sha256' => 'required|string|size:64',

            'config_snapshot.judge_provider' => 'nullable|string|max:255',
            'config_snapshot.judge_model' => 'nullable|string|max:255',
            'config_snapshot.judge_temperature' => 'required|numeric|min:0|max:2',

            'config_snapshot.correctness_rubric_version' => 'required|in:correctness-v1',
            'config_snapshot.faithfulness_rubric_version' => 'required|in:faithfulness-v1',
            'config_snapshot.answer_relevance_rubric_version' => 'required|in:answer-relevance-v1',
            'config_snapshot.abstention_rubric_version' => 'required|in:abstention-v1',
        ];

        foreach (self::RETRIEVAL_METRICS as $metric) {
            $rules["retrieval_metrics.$metric"] = 'nullable|numeric|min:0|max:1';
        }

        foreach (self::JUDGE_METRICS as $metric) {
            $rules["generation_metrics.$metric"] = 'required|array';
            $rules["generation_metrics.$metric.status"] =
                'required|in:completed,failed,not_applicable';
            $rules["generation_metrics.$metric.score"] =
                'nullable|numeric|min:0|max:1';
            $rules["generation_metrics.$metric.reason_code"] =
                'nullable|string|max:100';
            $rules["generation_metrics.$metric.short_reason"] =
                'nullable|string|max:1000';
        }

        foreach ([
            'binding.relevant_chunks',
            'retrieved',
        ] as $prefix) {
            $rules["$prefix.*.document_id"] = 'required|integer|min:1';
            $rules["$prefix.*.processing_run_id"] = 'required|integer|min:1';
            $rules["$prefix.*.processing_profile"] =
                'required|in:cloud,hybrid_local';
            $rules["$prefix.*.chunk_index"] = 'required|integer|min:0';
            $rules["$prefix.*.point_id"] = 'required|string|max:255';
            $rules["$prefix.*.text"] = 'required|string';
            $rules["$prefix.*.source"] = 'required|string|max:2000';
            $rules["$prefix.*.page"] = 'nullable|integer|min:1';
            $rules["$prefix.*.section"] = 'nullable|string|max:2000';
        }

        $rules['retrieved.*.retrieval_score'] = 'required|numeric';
        $rules['retrieved.*.reranker_score'] = 'nullable|numeric';

        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            $this->invalid();
        }

        $this->validateRetrievalConfigState(
            $payload['config_snapshot'],
            $pipeline,
        );

        $this->validateFiniteNumbers($payload);
        $this->validateTrustedChunks(
            $payload['binding']['relevant_chunks'],
            $snapshot,
        );
        $this->validateTrustedChunks(
            $payload['retrieved'],
            $snapshot,
        );

        $this->validateBindingState(
            $payload,
            $isAnswerable,
        );

        $this->validateRetrievalMetricState(
            $payload['retrieval_metrics'],
            $payload['status'],
            $isAnswerable,
        );

        $this->validateJudgeState(
            $payload['generation_metrics'],
            $payload['status'],
            $isAnswerable,
        );

        return $payload;
    }

    private function validateRetrievalConfigState(
        array $config,
        string $pipeline,
    ): void {
        $rrf = $config[
            'rrf_candidate_multiplier'
        ] ?? null;

        $rerank = $config[
            'rerank_candidate_multiplier'
        ] ?? null;

        $valid = match ($pipeline) {
            'dense_only' => (
                $rrf === null
                && $rerank === null
            ),

            'dense_sparse_rrf' => (
                is_int($rrf)
                && $rrf >= 1
                && $rerank === null
            ),

            'dense_sparse_rrf_reranker' => (
                is_int($rrf)
                && $rrf >= 1
                && is_int($rerank)
                && $rerank >= 1
            ),

            default => false,
        };

        if (! $valid) {
            $this->invalid();
        }
    }

    private function validateBindingState(
        array $payload,
        bool $isAnswerable,
    ): void {
        $binding = $payload['binding'];

        if (! $isAnswerable) {
            if (
                $payload['status'] !== 'completed'
                || $binding['status'] !== 'not_applicable'
                || $binding['relevant_chunks'] !== []
            ) {
                $this->invalid();
            }

            return;
        }

        if ($payload['status'] === 'completed') {
            if (
                $binding['status'] !== 'bound'
                || $binding['relevant_chunks'] === []
                || ! is_string($payload['generated_answer'])
                || trim($payload['generated_answer']) === ''
            ) {
                $this->invalid();
            }

            return;
        }

        if (
            ! in_array(
                $binding['status'],
                ['needs_review', 'unbound'],
                true,
            )
            || $payload['generated_answer'] !== null
        ) {
            $this->invalid();
        }
    }

    private function validateRetrievalMetricState(
        array $metrics,
        string $status,
        bool $isAnswerable,
    ): void {
        foreach (self::RETRIEVAL_METRICS as $metric) {
            $value = $metrics[$metric] ?? null;

            if (
                $status === 'completed'
                && $isAnswerable
                && $value === null
            ) {
                $this->invalid();
            }

            if (
                ($status !== 'completed' || ! $isAnswerable)
                && $value !== null
            ) {
                $this->invalid();
            }
        }
    }

    private function validateJudgeState(
        array $metrics,
        string $status,
        bool $isAnswerable,
    ): void {
        if ($status !== 'completed') {
            foreach (self::JUDGE_METRICS as $metric) {
                if (
                    $metrics[$metric]['status'] !== 'not_applicable'
                    || $metrics[$metric]['score'] !== null
                ) {
                    $this->invalid();
                }
            }

            return;
        }

        foreach (self::JUDGE_METRICS as $metric) {
            $entry = $metrics[$metric];
            $expectedApplicable = $isAnswerable
                ? $metric !== 'abstention'
                : $metric === 'abstention';

            if (! $expectedApplicable) {
                if (
                    $entry['status'] !== 'not_applicable'
                    || $entry['score'] !== null
                ) {
                    $this->invalid();
                }

                continue;
            }

            if (! in_array(
                $entry['status'],
                ['completed', 'failed'],
                true,
            )) {
                $this->invalid();
            }

            if (
                $entry['status'] === 'completed'
                && $entry['score'] === null
            ) {
                $this->invalid();
            }

            if (
                $entry['status'] === 'failed'
                && $entry['score'] !== null
            ) {
                $this->invalid();
            }
        }
    }

    private function validateTrustedChunks(
        array $chunks,
        array $snapshot,
    ): void {
        $targets = collect(
            $snapshot['document_targets'] ?? []
        )->keyBy('document_id');

        $seen = [];

        foreach ($chunks as $chunk) {
            $target = $targets->get(
                $chunk['document_id']
            );

            $identity = implode(':', [
                $chunk['document_id'],
                $chunk['processing_run_id'],
                $chunk['chunk_index'],
            ]);

            if (
                ! $target
                || (int) $target['processing_run_id']
                    !== (int) $chunk['processing_run_id']
                || $target['processing_profile']
                    !== $chunk['processing_profile']
                || isset($seen[$identity])
            ) {
                $this->invalid();
            }

            $seen[$identity] = true;
        }
    }

    private function validateFiniteNumbers(
        array $payload,
    ): void {
        $numbers = [
            $payload['timings_ms']['retrieval_ms'],
            $payload['timings_ms']['generation_ms'],
            $payload['timings_ms']['judge_ms'],
            $payload['timings_ms']['total_ms'],
        ];

        foreach (self::RETRIEVAL_METRICS as $metric) {
            $numbers[] = $payload['retrieval_metrics'][$metric] ?? null;
        }

        foreach (self::JUDGE_METRICS as $metric) {
            $numbers[] =
                $payload['generation_metrics'][$metric]['score']
                ?? null;
        }

        foreach ($numbers as $number) {
            if (
                $number !== null
                && ! is_finite((float) $number)
            ) {
                $this->invalid();
            }
        }
    }

    private function invalid(): never
    {
        throw new AiServiceException(
            'Invalid evaluation response.'
        );
    }
}
