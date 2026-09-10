<?php

namespace App\Services\Evaluation;

use App\Exceptions\AiServiceException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class EvaluationResponse
{
    public const METRICS = ['precision_at_k', 'recall_at_k', 'hit_rate_at_k', 'mrr_at_k', 'ndcg_at_k'];

    public const CONFIG = ['pipeline', 'metric_version', 'k', 'fusion_rrf_k', 'candidate_multiplier', 'cloud_embedding', 'cloud_reranker', 'local_embedding', 'local_reranker'];

    public function validate(array $payload, array $snapshot, int $k): array
    {
        $rules = ['metrics' => 'required|array', 'retrieved' => "present|array|max:$k", 'latency_ms' => 'required|numeric|min:0', 'config_snapshot' => 'required|array', 'config_snapshot.pipeline' => 'required|in:dense_sparse_rrf_reranker', 'config_snapshot.metric_version' => 'required|in:binary-chunk-v1', 'config_snapshot.k' => 'required|integer|in:'.$k, 'config_snapshot.fusion_rrf_k' => 'required|integer|in:60', 'config_snapshot.candidate_multiplier' => 'required|integer|in:2', 'retrieved.*.document_id' => 'required|integer|min:1', 'retrieved.*.processing_run_id' => 'required|integer|min:1', 'retrieved.*.processing_profile' => 'required|in:cloud,hybrid_local', 'retrieved.*.chunk_index' => 'required|integer|min:0', 'retrieved.*.point_id' => 'required|string|max:100'];
        foreach (self::METRICS as $metric) {
            $rules['metrics.'.$metric] = 'required|numeric|min:0|max:1';
        }
        foreach (['cloud_embedding', 'cloud_reranker', 'local_embedding', 'local_reranker'] as $key) {
            $rules['config_snapshot.'.$key] = 'required|string|max:255';
        }
        if (Validator::make($payload, $rules)->fails() || ! is_finite((float) ($payload['latency_ms'] ?? INF))) {
            $this->invalid();
        }
        $targets = collect($snapshot['document_targets'])->keyBy('document_id');
        $seen = [];
        foreach ($payload['retrieved'] as $chunk) {
            $target = $targets->get($chunk['document_id']);
            $identity = $chunk['document_id'].':'.$chunk['chunk_index'];
            if (! $target || (int) $target['processing_run_id'] !== (int) $chunk['processing_run_id'] || $target['processing_profile'] !== $chunk['processing_profile'] || isset($seen[$identity])) {
                $this->invalid();
            }
            $seen[$identity] = true;
        }

        return ['metrics' => Arr::only($payload['metrics'], self::METRICS), 'retrieved' => array_map(fn ($chunk) => Arr::only($chunk, ['document_id', 'processing_run_id', 'processing_profile', 'chunk_index', 'point_id']), $payload['retrieved']), 'latency_ms' => (float) $payload['latency_ms'], 'config_snapshot' => Arr::only($payload['config_snapshot'], self::CONFIG)];
    }

    private function invalid(): never
    {
        throw new AiServiceException('Invalid evaluation response.');
    }
}
