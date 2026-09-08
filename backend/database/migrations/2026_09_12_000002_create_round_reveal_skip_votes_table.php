<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('round_reveal_skip_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('rounds')->cascadeOnDelete();
            $table->foreignId('room_player_id')->constrained('room_players')->cascadeOnDelete();
            $table->timestamps();

            // One vote per player per round - a repeat tap is a zero-row
            // insert, and the next round (a new round_id) starts with a clean
            // slate, so no per-round cleanup is needed.
            $table->unique(['round_id', 'room_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('round_reveal_skip_votes');
    }
};
