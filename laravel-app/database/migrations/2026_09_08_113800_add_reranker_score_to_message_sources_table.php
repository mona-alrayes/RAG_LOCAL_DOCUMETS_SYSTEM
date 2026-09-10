<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_sources', function (Blueprint $table): void {
            $table
                ->double('reranker_score')
                ->nullable()
                ->after('relevance_score');
        });
    }

    public function down(): void
    {
        Schema::table('message_sources', function (Blueprint $table): void {
            $table->dropColumn('reranker_score');
        });
    }
};
