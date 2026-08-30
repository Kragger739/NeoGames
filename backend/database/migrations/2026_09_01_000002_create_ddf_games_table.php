<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ddf_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_room_id')->unique()->constrained('game_rooms')->cascadeOnDelete();
            $table->string('state')->default('lobby');
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamp('stage_started_at')->nullable();
            $table->unsignedTinyInteger('rounds_per_voting')->default(3);
            $table->unsignedTinyInteger('rounds_played_this_cycle')->default(0);
            $table->unsignedSmallInteger('question_timer_seconds')->default(30);
            $table->unsignedSmallInteger('voting_timer_seconds')->default(30);
            $table->foreignId('current_question_id')->nullable()->constrained('ddf_questions')->nullOnDelete();
            $table->unsignedInteger('current_question_number')->default(0);
            $table->unsignedInteger('voting_round_number')->default(0);
            $table->boolean('is_paused')->default(false);
            $table->unsignedSmallInteger('paused_remaining_seconds')->nullable();
            $table->boolean('is_revote')->default(false);
            $table->json('tie_candidate_player_ids')->nullable();
            $table->foreignId('winner_room_player_id')->nullable()->constrained('room_players')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ddf_games');
    }
};
