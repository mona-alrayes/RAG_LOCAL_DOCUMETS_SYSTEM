<?php

namespace Tests\Feature\Evaluation;

use App\Exceptions\AiServiceException;
use App\Services\Evaluation\EvaluationResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvaluationResponseTest extends TestCase
{
    public function test_preserves_v2_correctness_checks_and_v4_prompt(): void
    {
        // العقد الجديد يحفظ أدلة المطابقة، مع استمرار قبول fixtures للإصدار القديم.
        $payload = $this->validPayload();
        $payload['config_snapshot']['answer_prompt_version'] = 'rag-answer-v4';
        $payload['config_snapshot']['correctness_rubric_version'] = 'correctness-v2';
        $checks = [['reference_quote' => 'Twenty years', 'status' => 'supported', 'answer_quote' => '20 years']];
        $payload['generation_metrics']['correctness']['checks'] = $checks;
        $result = app(EvaluationResponse::class)->validate($payload, $this->snapshot(), 5);
        $this->assertSame($checks, $result['generation_metrics']['correctness']['checks']);
    }

    public function test_completed_v2_correctness_requires_point_checks(): void
    {
        $payload = $this->validPayload();
        $payload['config_snapshot']['correctness_rubric_version'] = 'correctness-v2';
        $this->expectException(AiServiceException::class);
        app(EvaluationResponse::class)->validate($payload, $this->snapshot(), 5);
    }

    #[DataProvider('invalidResponses')]
    public function test_rejects_invalid_or_untrusted_full_rag_response(
        array $changes,
    ): void {
        $payload = array_replace_recursive(
            $this->validPayload(),
            $changes,
        );

        $this->expectException(
            AiServiceException::class
        );

        app(EvaluationResponse::class)->validate(
            $payload,
            $this->snapshot(),
            5,
            'dense_sparse_rrf_reranker',
            true,
        );
    }

    public function test_accepts_complete_answerable_response(): void
    {
        $result = app(EvaluationResponse::class)->validate(
            $this->validPayload(),
            $this->snapshot(),
            5,
            'dense_sparse_rrf_reranker',
            true,
        );

        $this->assertSame(
            'completed',
            $result['status'],
        );

        $this->assertSame(
            1.0,
            $result['retrieval_metrics']['recall_at_k'],
        );

        $this->assertSame(
            'bound',
            $result['binding']['status'],
        );

        $this->assertSame(
            'rag-answer-v2',
            $result['config_snapshot']['answer_prompt_version'],
        );
    }

    public function test_accepts_unanswerable_with_null_retrieval_metrics(): void
    {
        $payload = $this->validPayload();

        $payload['binding'] = [
            'status' => 'not_applicable',
            'relevant_chunks' => [],
            'evidence' => [],
        ];

        foreach (
            EvaluationResponse::RETRIEVAL_METRICS as $metric
        ) {
            $payload['retrieval_metrics'][$metric] = null;
        }

        foreach ([
            'correctness',
            'faithfulness',
            'answer_relevance',
        ] as $metric) {
            $payload['generation_metrics'][$metric] = [
                'status' => 'not_applicable',
                'score' => null,
                'reason_code' => null,
                'short_reason' => null,
            ];
        }

        $payload['generation_metrics']['abstention'] = [
            'status' => 'completed',
            'score' => 1.0,
            'reason_code' => 'correct_abstention',
            'short_reason' => 'The answer abstained.',
        ];

        $result = app(EvaluationResponse::class)->validate(
            $payload,
            $this->snapshot(),
            5,
            'dense_sparse_rrf_reranker',
            false,
        );

        $this->assertNull(
            $result['retrieval_metrics']['recall_at_k']
        );

        $this->assertSame(
            1.0,
            $result['generation_metrics']['abstention']['score'],
        );
    }

    public function test_accepts_binding_failure_without_generation(): void
    {
        $payload = $this->validPayload();

        $payload['status'] = 'binding_failed';
        $payload['binding']['status'] = 'needs_review';
        $payload['binding']['relevant_chunks'] = [];
        $payload['generated_answer'] = null;
        $payload['retrieved'] = [];

        foreach (
            EvaluationResponse::RETRIEVAL_METRICS as $metric
        ) {
            $payload['retrieval_metrics'][$metric] = null;
        }

        foreach (
            EvaluationResponse::JUDGE_METRICS as $metric
        ) {
            $payload['generation_metrics'][$metric] = [
                'status' => 'not_applicable',
                'score' => null,
                'reason_code' => null,
                'short_reason' => null,
            ];
        }

        $payload['timings_ms']['retrieval_ms'] = null;
        $payload['timings_ms']['generation_ms'] = null;
        $payload['timings_ms']['judge_ms'] = null;
        $payload['config_snapshot']['answer_provider'] = null;
        $payload['config_snapshot']['answer_model'] = null;
        $payload['config_snapshot']['judge_provider'] = null;
        $payload['config_snapshot']['judge_model'] = null;

        $result = app(EvaluationResponse::class)->validate(
            $payload,
            $this->snapshot(),
            5,
            'dense_sparse_rrf_reranker',
            true,
        );

        $this->assertSame(
            'binding_failed',
            $result['status'],
        );
    }

    public function test_accepts_only_requested_ablation_pipeline(): void
    {
        $payload = $this->validPayload();
        $payload['config_snapshot']['pipeline'] = 'dense_only';
        $payload['config_snapshot'][
            'rrf_candidate_multiplier'
        ] = null;
        $payload['config_snapshot'][
            'rerank_candidate_multiplier'
        ] = null;

        $validator = app(EvaluationResponse::class);

        $result = $validator->validate(
            $payload,
            $this->snapshot(),
            5,
            'dense_only',
            true,
        );

        $this->assertSame(
            'dense_only',
            $result['config_snapshot']['pipeline'],
        );

        $this->expectException(
            AiServiceException::class
        );

        $validator->validate(
            $payload,
            $this->snapshot(),
            5,
            'dense_sparse_rrf',
            true,
        );
    }

    public function test_accepts_configurable_retrieval_multipliers(): void
    {
        $payload = $this->validPayload();

        $payload['config_snapshot'][
            'rrf_candidate_multiplier'
        ] = 3;

        $payload['config_snapshot'][
            'rerank_candidate_multiplier'
        ] = 4;

        $result = app(
            EvaluationResponse::class
        )->validate(
            $payload,
            $this->snapshot(),
            5,
            'dense_sparse_rrf_reranker',
            true,
        );

        $this->assertSame(
            3,
            $result['config_snapshot'][
                'rrf_candidate_multiplier'
            ],
        );

        $this->assertSame(
            4,
            $result['config_snapshot'][
                'rerank_candidate_multiplier'
            ],
        );
    }

    public static function invalidResponses(): array
    {
        return [
            'foreign retrieved document' => [[
                'retrieved' => [
                    ['document_id' => 99],
                ],
            ]],
            'foreign binding run' => [[
                'binding' => [
                    'relevant_chunks' => [
                        ['processing_run_id' => 99],
                    ],
                ],
            ]],
            'wrong profile' => [[
                'retrieved' => [
                    ['processing_profile' => 'hybrid_local'],
                ],
            ]],
            'negative metric' => [[
                'retrieval_metrics' => [
                    'recall_at_k' => -1,
                ],
            ]],
            'metric above one' => [[
                'retrieval_metrics' => [
                    'recall_at_k' => 1.5,
                ],
            ]],
            'wrong k' => [[
                'config_snapshot' => [
                    'k' => 10,
                ],
            ]],
            'wrong prompt version' => [[
                'config_snapshot' => [
                    'answer_prompt_version' => 'other',
                ],
            ]],
            'completed judge without score' => [[
                'generation_metrics' => [
                    'correctness' => [
                        'score' => null,
                    ],
                ],
            ]],
        ];
    }

    private function validPayload(): array
    {
        $chunk = [
            'point_id' => 'point-1',
            'document_id' => 1,
            'processing_run_id' => 2,
            'processing_profile' => 'cloud',
            'chunk_index' => 0,
            'text' => 'Evidence text',
            'source' => 'notes.txt',
            'page' => 1,
            'section' => 'Section',
        ];

        return [
            'status' => 'completed',

            'binding' => [
                'status' => 'bound',
                'relevant_chunks' => [$chunk],
                'evidence' => [
                    [
                        'evidence_index' => 0,
                        'status' => 'bound',
                        'score' => 1.0,
                        'matched_chunks' => [$chunk],
                        'competing_matches' => 0,
                    ],
                ],
            ],

            'retrieval_metrics' => [
                'precision_at_k' => 0.2,
                'recall_at_k' => 1.0,
                'hit_rate_at_k' => 1.0,
                'mrr_at_k' => 1.0,
                'ndcg_at_k' => 1.0,
            ],

            'generated_answer' => 'Answer',

            'generation_metrics' => [
                'correctness' => [
                    'status' => 'completed',
                    'score' => 1.0,
                    'reason_code' => 'pass',
                    'short_reason' => 'Correct.',
                ],
                'faithfulness' => [
                    'status' => 'completed',
                    'score' => 1.0,
                    'reason_code' => 'pass',
                    'short_reason' => 'Grounded.',
                ],
                'answer_relevance' => [
                    'status' => 'completed',
                    'score' => 1.0,
                    'reason_code' => 'pass',
                    'short_reason' => 'Relevant.',
                ],
                'abstention' => [
                    'status' => 'not_applicable',
                    'score' => null,
                    'reason_code' => null,
                    'short_reason' => null,
                ],
            ],

            'retrieved' => [
                $chunk + [
                    'retrieval_score' => 0.9,
                    'reranker_score' => 0.8,
                ],
            ],

            'timings_ms' => [
                'retrieval_ms' => 10.0,
                'generation_ms' => 20.0,
                'judge_ms' => 30.0,
                'total_ms' => 60.0,
            ],

            'config_snapshot' => [
                'pipeline' => 'dense_sparse_rrf_reranker',
                'metric_version' => 'binary-chunk-v1',
                'k' => 5,
                'rrf_candidate_multiplier' => 2,
                'rerank_candidate_multiplier' => 2,

                'cloud_embedding' => 'jina',
                'cloud_reranker' => 'jina',
                'local_embedding' => 'bge',
                'local_reranker' => 'bge',

                'answer_provider' => 'fake_llm',
                'answer_model' => 'qwen',
                'answer_temperature' => 0.2,
                'answer_prompt_version' => 'rag-answer-v2',
                'answer_prompt_sha256' => str_repeat('a', 64),

                'judge_provider' => 'fake_llm',
                'judge_model' => 'qwen',
                'judge_temperature' => 0.0,

                'correctness_rubric_version' => 'correctness-v1',
                'faithfulness_rubric_version' => 'faithfulness-v1',
                'answer_relevance_rubric_version' => 'answer-relevance-v1',
                'abstention_rubric_version' => 'abstention-v1',
            ],
        ];
    }

    private function snapshot(): array
    {
        return [
            'document_targets' => [
                [
                    'document_id' => 1,
                    'processing_run_id' => 2,
                    'processing_profile' => 'cloud',
                ],
            ],
        ];
    }
}
