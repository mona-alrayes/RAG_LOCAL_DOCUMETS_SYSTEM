<?php

use App\Enums\MessageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('role', 16);

            $table->string('status', 16)
                ->default(MessageStatus::Pending->value);

            $table->longText('content')->nullable();

            $table->json('execution_snapshot')->nullable();
            $table->json('metrics')->nullable();

            $table->timestamps();

            $table->index([
                'conversation_id',
                'created_at',
                'id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
