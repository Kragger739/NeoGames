<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ddf_questions', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->text('text');
            $table->string('correct_answer');
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ddf_questions');
    }
};
