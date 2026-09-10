<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('dataset_version', 100);
            $table->char('dataset_sha256', 64);
            $table->string('dataset_path');
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedTinyInteger('k');
            $table->unsignedInteger('questions_count');
            $table->unsignedInteger('completed_questions')->default(0);
            $table->json('targets_snapshot');
            $table->json('config_snapshot')->nullable();
            $table->json('metrics')->nullable();
            $table->json('latency_summary')->nullable();
            $table->json('results')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_runs');
    }
};
