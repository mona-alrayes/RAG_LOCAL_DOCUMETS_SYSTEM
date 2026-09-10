<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('processing_run_id')
                ->constrained('document_processing_runs')
                ->cascadeOnDelete();

            $table->string('qdrant_point_id', 64);

            $table->unsignedInteger('chunk_index');

            $table->json('source_snapshot');

            $table->double('relevance_score')->nullable();

            $table->timestamps();

            $table->unique([
                'message_id',
                'qdrant_point_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_sources');
    }
};
