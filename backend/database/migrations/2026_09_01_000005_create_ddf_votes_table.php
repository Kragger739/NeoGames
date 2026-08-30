<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ddf_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_room_id')->constrained('game_rooms')->cascadeOnDelete();
            $table->unsignedInteger('voting_round_number');
            $table->foreignId('voter_room_player_id')->constrained('room_players')->cascadeOnDelete();
            $table->foreignId('target_room_player_id')->constrained('room_players')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['game_room_id', 'voting_round_number', 'voter_room_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ddf_votes');
    }
};
