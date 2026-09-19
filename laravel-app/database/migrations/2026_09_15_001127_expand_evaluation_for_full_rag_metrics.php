<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('name');
            $table->string('dataset_version', 100);
            $table->unsignedTinyInteger('schema_version');
            $table->char('sha256', 64);
            $table->string('file_path');
            $table->unsignedInteger('questions_count');

            $table->timestamps();

            $table->index(['created_by', 'sha256']);
            $table->index(['dataset_version', 'schema_version']);
        });

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->foreignId('evaluation_dataset_id')
                ->nullable()
                ->after('created_by')
                ->constrained('evaluation_datasets')
                ->restrictOnDelete();

            $table->unsignedInteger('successful_questions')
                ->default(0)
                ->after('completed_questions');

            $table->unsignedInteger('failed_questions')
                ->default(0)
                ->after('successful_questions');

            $table->unsignedInteger('answerable_questions')
                ->default(0)
                ->after('failed_questions');

            $table->unsignedInteger('unanswerable_questions')
                ->default(0)
                ->after('answerable_questions');
        });

        Schema::create('evaluation_question_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('evaluation_run_id')
                ->constrained('evaluation_runs')
                ->cascadeOnDelete();

            $table->string('question_id', 100);
            $table->unsignedInteger('sequence');

            $table->longText('question');
            $table->longText('reference_answer')->nullable();

            $table->string('split', 32)->default('held_out');
            $table->string('category', 100)->nullable();
            $table->boolean('is_answerable')->default(true);

            $table->longText('generated_answer')->nullable();

            $table->string('status', 30)->default('pending');
            $table->string('error_stage', 30)->nullable();
            $table->string('error_code', 100)->nullable();

            $table->decimal('precision_at_k', 8, 6)->nullable();
            $table->decimal('recall_at_k', 8, 6)->nullable();
            $table->decimal('hit_rate_at_k', 8, 6)->nullable();
            $table->decimal('mrr_at_k', 8, 6)->nullable();
            $table->decimal('ndcg_at_k', 8, 6)->nullable();

            $table->decimal('correctness', 8, 6)->nullable();
            $table->decimal('faithfulness', 8, 6)->nullable();
            $table->decimal('answer_relevance', 8, 6)->nullable();
            $table->boolean('abstention_correct')->nullable();

            $table->decimal('retrieval_ms', 14, 3)->nullable();
            $table->decimal('generation_ms', 14, 3)->nullable();
            $table->decimal('judge_ms', 14, 3)->nullable();
            $table->decimal('total_ms', 14, 3)->nullable();

            $table->json('golden_evidence')->nullable();
            $table->json('relevant_chunks')->nullable();
            $table->json('retrieved_candidates')->nullable();
            $table->json('reranked_chunks')->nullable();
            $table->json('retrieved_context')->nullable();
            $table->json('judge_details')->nullable();

            $table->timestamps();

            $table->unique(
                ['evaluation_run_id', 'question_id'],
                'evaluation_question_results_run_question_unique',
            );

            $table->unique(
                ['evaluation_run_id', 'sequence'],
                'evaluation_question_results_run_sequence_unique',
            );

            $table->index(['evaluation_run_id', 'status']);
            $table->index(['evaluation_run_id', 'split']);
            $table->index(['evaluation_run_id', 'category']);
            $table->index(['evaluation_run_id', 'is_answerable'], 'eval_q_results_run_answerable_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_question_results');

        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->dropForeign(['evaluation_dataset_id']);

            $table->dropColumn([
                'evaluation_dataset_id',
                'successful_questions',
                'failed_questions',
                'answerable_questions',
                'unanswerable_questions',
            ]);
        });

        Schema::dropIfExists('evaluation_datasets');
    }
};
