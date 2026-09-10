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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            /*
             * Points to the currently active successfully indexed processing run.
             *
             * This intentionally has no database-level foreign key because
             * document_processing_runs itself references documents, and adding
             * the reverse FK here would create a circular schema dependency.
             *
             * The application/domain layer must guarantee that the active run:
             * - belongs to this document
             * - has status "indexed"
             */
            $table->unsignedBigInteger('active_processing_run_id')->nullable();

            $table->string('original_name');
            $table->string('stored_name');
            $table->string('title')->nullable();

            $table->string('file_path', 1024);
            $table->string('file_type', 16);
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');

            $table->char('sha256', 64);

            $table->string('status', 32)->default('pending');

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'sha256']);
            $table->index(['user_id', 'created_at']);
            $table->index('active_processing_run_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
