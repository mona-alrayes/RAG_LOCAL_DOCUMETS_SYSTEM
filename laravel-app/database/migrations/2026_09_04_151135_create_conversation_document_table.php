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
        Schema::create('conversation_document', function (Blueprint $table) {
            $table->foreignId('conversation_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('document_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unique(['conversation_id', 'document_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_document');
    }
};
