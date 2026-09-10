<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recorded audio take for one line in one round. A retake inserts a new
 * row and flips the previous take's is_selected to false, so the latest
 * take always wins; assembly reads only is_selected rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dub_takes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dub_game_id')->constrained('dub_games')->cascadeOnDelete();
            $table->foreignId('dub_clip_line_id')->constrained('dub_clip_lines')->cascadeOnDelete();
            $table->foreignId('room_player_id')->constrained('room_players')->cascadeOnDelete();
            $table->unsignedInteger('round_number');
            $table->string('audio_path');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('is_selected')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index(['dub_game_id', 'round_number', 'dub_clip_line_id'], 'dub_take_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dub_takes');
    }
};
