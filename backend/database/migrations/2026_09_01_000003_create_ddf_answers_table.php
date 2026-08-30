<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ddf_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_room_id')->constrained('game_rooms')->cascadeOnDelete();
            $table->foreignId('ddf_question_id')->constrained('ddf_questions')->cascadeOnDelete();
            $table->foreignId('room_player_id')->constrained('room_players')->cascadeOnDelete();
            $table->unsignedInteger('question_number');
            $table->text('answer_text')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();

            $table->unique(['game_room_id', 'question_number', 'room_player_id']);
            $table->index(['game_room_id', 'ddf_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ddf_answers');
    }
};
