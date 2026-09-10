<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_processing_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('profile', 32);
            $table->string('status', 32)->default('pending');
            $table->string('kind', 32)->default('initial');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('indexing_started_at')->nullable();

            $table->json('profile_snapshot');

            $table->unsignedInteger('total_pages')->nullable();
            $table->unsignedBigInteger('total_chunks')->default(0);
            $table->unsignedBigInteger('vector_count')->default(0);
            $table->unsignedInteger('vector_dimension')->nullable();

            $table->json('stage_timings_ms');
            $table->json('warnings')->nullable();

            $table->string('error_code')->nullable();
            $table->text('failure_reason')->nullable();

            $table->string('qdrant_collection')->nullable();

            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index(['document_id', 'profile', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_processing_runs');
    }
};
