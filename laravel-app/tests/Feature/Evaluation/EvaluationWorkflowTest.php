<?php

namespace Tests\Feature\Evaluation;

use App\Jobs\EvaluateQuestionJob;
use App\Services\Evaluation\DatasetUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\AdminFixtures;
use Tests\Support\GoldenDatasetFile;
use Tests\TestCase;

class EvaluationWorkflowTest extends TestCase
{
    use AdminFixtures, RefreshDatabase;

    private function fixture(
        int $questions = 1,
    ): array {
        $document = $this->document();

        $document->forceFill([
            'status' => 'ready',
        ])->save();

        $processing = $document
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
            'active_processing_run_id' => $processing->id,
        ])->save();

        $examples = [];

        for ($i = 1; $i <= $questions; $i++) {
            $examples[] = [
                'question_id' => "q{$i}",
                'question' => "Question {$i}?",
                'reference_answer' => "Answer {$i}",
                'split' => $i === 1
                    ? 'development'
                    : 'held_out',
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
            ];
        }

        return [
            $document,
            $processing,
            [
                'schema_version' => 2,
                'dataset_version' => 'gold-v2',
                'examples' => $examples,
            ],
        ];
    }

    public function test_full_rag_run_persists_normalized_question_result(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->configureAi();

        [$document, $processing, $dataset] =
            $this->fixture();

        $run = app(DatasetUpload::class)->create(
            $this->admin(),
            'Notes benchmark',
            GoldenDatasetFile::fromArray(
                $dataset
            ),
            5,
            'dense_sparse_rrf_reranker',
            [$document->id],
        );

        $this->assertSame(
            'queued',
            $run->status,
        );

        $this->assertDatabaseHas(
            'evaluation_datasets',
            [
                'id' => $run->evaluation_dataset_id,
                'schema_version' => 2,
                'questions_count' => 1,
            ],
        );

        $this->assertDatabaseHas(
            'evaluation_question_results',
            [
                'evaluation_run_id' => $run->id,
                'question_id' => 'q1',
                'status' => 'pending',
            ],
        );

        Queue::assertPushed(
            EvaluateQuestionJob::class
        );

        Http::fake([
            '*/api/v1/admin/evaluate-question' => Http::response(
                $this->response(
                    $document->id,
                    $processing->id,
                ),
            ),
        ]);

        $this->app->call([
            new EvaluateQuestionJob(
                $run->id,
                0,
            ),
            'handle',
        ]);

        $run->refresh();
        $question = $run->questionResults()
            ->first();

        $this->assertSame(
            'completed',
            $run->status,
        );
        $this->assertSame(
            1,
            $run->completed_questions,
        );
        $this->assertSame(
            1,
            $run->successful_questions,
        );
        $this->assertSame(
            0,
            $run->failed_questions,
        );

        $this->assertSame(
            'completed',
            $question->status,
        );
        $this->assertSame(
            'Answer from RAG',
            $question->generated_answer,
        );
        $this->assertEquals(
            1.0,
            $question->recall_at_k,
        );
        $this->assertEquals(
            1.0,
            $question->faithfulness,
        );

        $this->assertCount(
            1,
            $question->relevant_chunks,
        );
        $this->assertCount(
            1,
            $question->retrieved_context,
        );

        $this->assertEquals(
            1.0,
            $run->metrics['recall_at_k'],
        );
        $this->assertEquals(
            60.0,
            $run->latency_summary['mean_ms'],
        );

        Http::assertSent(
            fn ($request) => $request['user_id']
                    === $document->user_id
                && $request['question_id']
                    === 'q1'
                && $request['is_answerable']
                    === true
                && $request['evidence'][0][
                    'evidence_text'
                ] === 'Evidence text'
                && $request['document_targets'][0][
                    'processing_run_id'
                ] === $processing->id
                && ! isset(
                    $request['recent_completed_turns']
                ),
        );

        // A stale duplicate job remains idempotent.
        $this->app->call([
            new EvaluateQuestionJob(
                $run->id,
                0,
            ),
            'handle',
        ]);

        Http::assertSentCount(1);
    }

    public function test_config_snapshot_key_order_does_not_trigger_pipeline_changed(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->configureAi();

        [$document, $processing, $dataset] =
            $this->fixture(2);

        $run = app(DatasetUpload::class)->create(
            $this->admin(),
            'Config snapshot order',
            GoldenDatasetFile::fromArray(
                $dataset
            ),
            5,
            'dense_sparse_rrf_reranker',
            [$document->id],
        );

        $first = $this->response(
            $document->id,
            $processing->id,
        );

        $second = $this->response(
            $document->id,
            $processing->id,
        );

        $second['config_snapshot'] =
            array_reverse(
                $second['config_snapshot'],
                true,
            );

        // FastAPI returns 0.0 while MySQL JSON may restore it as 0.
        $second['config_snapshot']['judge_temperature'] = 0.0;

        Http::fake([
            '*/api/v1/admin/evaluate-question' =>
                Http::sequence()
                    ->push($first)
                    ->push(
                        json_encode(
                            $second,
                            JSON_PRESERVE_ZERO_FRACTION,
                        ),
                        200,
                        ['Content-Type' => 'application/json'],
                    ),
        ]);

        $this->app->call([
            new EvaluateQuestionJob(
                $run->id,
                0,
            ),
            'handle',
        ]);

        // Simulate MySQL JSON numeric normalization after persistence.
        $run->refresh();
        $storedConfig = $run->config_snapshot;
        $storedConfig['judge_temperature'] = 0;

        $run->forceFill([
            'config_snapshot' => $storedConfig,
        ])->save();

        $run->refresh();

        $this->assertSame(
            0,
            $run->config_snapshot['judge_temperature'],
        );

        $this->app->call([
            new EvaluateQuestionJob(
                $run->id,
                1,
            ),
            'handle',
        ]);

        $run->refresh();

        $this->assertSame(
            'completed',
            $run->status,
        );

        $this->assertNull(
            $run->error_code,
        );

        $this->assertSame(
            2,
            $run->successful_questions,
        );
    }

    public function test_binding_failure_is_isolated_and_next_question_is_queued(): void
    {
        Queue::fake();
        Storage::fake('local');

        $this->configureAi();

        [$document, $processing, $dataset] =
            $this->fixture(2);

        $run = app(DatasetUpload::class)->create(
            $this->admin(),
            'Binding isolation',
            GoldenDatasetFile::fromArray(
                $dataset
            ),
            5,
            'dense_sparse_rrf_reranker',
            [$document->id],
        );

        $payload = $this->response(
            $document->id,
            $processing->id,
        );

        $payload['status'] = 'binding_failed';
        $payload['binding']['status'] =
            'needs_review';
        $payload['binding']['relevant_chunks'] = [];
        $payload['generated_answer'] = null;
        $payload['retrieved'] = [];

        foreach ([
            'precision_at_k',
            'recall_at_k',
            'hit_rate_at_k',
            'mrr_at_k',
            'ndcg_at_k',
        ] as $metric) {
            $payload[
                'retrieval_metrics'
            ][$metric] = null;
        }

        foreach ([
            'correctness',
            'faithfulness',
            'answer_relevance',
            'abstention',
        ] as $metric) {
            $payload[
                'generation_metrics'
            ][$metric] = [
                'status' => 'not_applicable',
                'score' => null,
                'reason_code' => null,
                'short_reason' => null,
            ];
        }

        $payload['timings_ms'] = [
            'retrieval_ms' => null,
            'generation_ms' => null,
            'judge_ms' => null,
            'total_ms' => 4.0,
        ];

        $payload['config_snapshot']['answer_provider']
            = null;
        $payload['config_snapshot']['answer_model']
            = null;
        $payload['config_snapshot']['judge_provider']
            = null;
        $payload['config_snapshot']['judge_model']
            = null;

        Http::fake([
            '*/api/v1/admin/evaluate-question' => Http::response($payload),
        ]);

        $this->app->call([
            new EvaluateQuestionJob(
                $run->id,
                0,
            ),
            'handle',
        ]);

        $run->refresh();
        $first = $run->questionResults()
            ->where('sequence', 1)
            ->first();

        $this->assertSame(
            'running',
            $run->status,
        );
        $this->assertSame(
            1,
            $run->completed_questions,
        );
        $this->assertSame(
            0,
            $run->successful_questions,
        );
        $this->assertSame(
            1,
            $run->failed_questions,
        );

        $this->assertSame(
            'failed',
            $first->status,
        );
        $this->assertSame(
            'binding',
            $first->error_stage,
        );
        $this->assertSame(
            'golden_binding_needs_review',
            $first->error_code,
        );

        Queue::assertPushed(
            EvaluateQuestionJob::class,
            fn ($job) => $job->evaluationRunId === $run->id
                && $job->questionIndex === 1,
        );
    }

    public function test_reprocessed_document_fails_entire_run_before_fastapi(): void
    {
        Queue::fake();
        Storage::fake('local');
        Http::fake();

        [$document, $processing, $dataset] =
            $this->fixture();

        $run = app(DatasetUpload::class)->create(
            $this->admin(),
            'Snapshot',
            GoldenDatasetFile::fromArray(
                $dataset
            ),
            5,
            'dense_sparse_rrf_reranker',
            [$document->id],
        );

        $document->forceFill([
            'active_processing_run_id' => null,
        ])->save();

        $this->app->call([
            new EvaluateQuestionJob(
                $run->id,
                0,
            ),
            'handle',
        ]);

        $this->assertSame(
            'failed',
            $run->fresh()->status,
        );
        $this->assertSame(
            'index_changed',
            $run->fresh()->error_code,
        );

        Http::assertNothingSent();
    }

    public function test_stale_worker_failure_does_not_overwrite_later_question(): void
    {
        Queue::fake();
        Storage::fake('local');

        [$document, $processing, $dataset] =
            $this->fixture(2);

        $run = app(DatasetUpload::class)->create(
            $this->admin(),
            'Worker state',
            GoldenDatasetFile::fromArray(
                $dataset
            ),
            5,
            'dense_sparse_rrf_reranker',
            [$document->id],
        );

        $run->update([
            'status' => 'running',
            'completed_questions' => 1,
        ]);

        (
            new EvaluateQuestionJob(
                $run->id,
                0,
            )
        )->failed(
            new \RuntimeException(
                'Old duplicate failed'
            )
        );

        $this->assertSame(
            'running',
            $run->fresh()->status,
        );
        $this->assertNull(
            $run->fresh()->error_code,
        );
    }

    public function test_cross_owner_selected_corpus_is_rejected(): void
    {
        Queue::fake();
        Storage::fake('local');

        [$document, $processing, $dataset] =
            $this->fixture();

        $foreign = $this->document();
        $foreign->forceFill([
            'status' => 'ready',
        ])->save();

        $foreignRun = $foreign
            ->processingRuns()
            ->create([
                'profile' => 'cloud',
                'status' => 'indexed',
                'kind' => 'initial',
                'profile_snapshot' => [],
                'stage_timings_ms' => [],
                'total_chunks' => 1,
                'vector_count' => 1,
            ]);

        $foreign->forceFill([
            'active_processing_run_id' => $foreignRun->id,
        ])->save();

        $this->expectException(
            ValidationException::class
        );

        app(DatasetUpload::class)->create(
            $this->admin(),
            'Invalid owners',
            GoldenDatasetFile::fromArray(
                $dataset
            ),
            5,
            'dense_sparse_rrf_reranker',
            [
                $document->id,
                $foreign->id,
            ],
        );
    }

    private function configureAi(): void
    {
        config([
            'services.ai_service.base_url' => 'http://ai.test',
            'services.ai_service.internal_api_key' => 'test-key',
        ]);
    }

    private function response(
        int $documentId,
        int $processingRunId,
    ): array {
        $chunk = [
            'point_id' => 'p1',
            'document_id' => $documentId,
            'processing_run_id' => $processingRunId,
            'processing_profile' => 'cloud',
            'chunk_index' => 1,
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
                'precision_at_k' => .2,
                'recall_at_k' => 1.0,
                'hit_rate_at_k' => 1.0,
                'mrr_at_k' => 1.0,
                'ndcg_at_k' => 1.0,
            ],

            'generated_answer' => 'Answer from RAG',

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
                    'retrieval_score' => .9,
                    'reranker_score' => .8,
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
                'cloud_embedding' => 'jina-embeddings-v3',
                'cloud_reranker' => 'jina-reranker-v2-base-multilingual',
                'local_embedding' => 'BAAI/bge-m3',
                'local_reranker' => 'BAAI/bge-reranker-v2-m3',
                'answer_provider' => 'fake_llm',
                'answer_model' => 'qwen',
                'answer_temperature' => .2,
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
}
