<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each player's 1-5 rating of the finished dub for one round. The average
 * of a round's ratings is that round's co-op score, added to total_score.
 * One rating per (game, player, round).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dub_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dub_game_id')->constrained('dub_games')->cascadeOnDelete();
            $table->foreignId('room_player_id')->constrained('room_players')->cascadeOnDelete();
            $table->unsignedInteger('round_number');
            $table->unsignedTinyInteger('score'); // 1-5
            $table->timestamp('created_at')->nullable();

            $table->unique(['dub_game_id', 'room_player_id', 'round_number'], 'dub_rating_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dub_ratings');
    }
};
