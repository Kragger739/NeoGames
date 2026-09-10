<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which seat is voicing which character this round. One row per
 * (game, character, round); room_player_id null = unclaimed (that
 * character keeps its original audio in the assembled dub).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dub_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dub_game_id')->constrained('dub_games')->cascadeOnDelete();
            $table->foreignId('dub_clip_character_id')->constrained('dub_clip_characters')->cascadeOnDelete();
            $table->foreignId('room_player_id')->nullable()->constrained('room_players')->nullOnDelete();
            $table->unsignedInteger('round_number');
            $table->timestamps();

            $table->unique(['dub_game_id', 'dub_clip_character_id', 'round_number'], 'dub_role_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dub_role_assignments');
    }
};
