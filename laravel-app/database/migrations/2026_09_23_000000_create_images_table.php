<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->string('imageable_type');
            $table->unsignedBigInteger('imageable_id');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->timestamps();

            $table->unique(['imageable_type', 'imageable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
