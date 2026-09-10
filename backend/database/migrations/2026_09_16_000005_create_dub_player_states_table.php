<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-seat "Dub Together" state - mirrors ddf_player_states. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dub_player_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_player_id')->unique()->constrained('room_players')->cascadeOnDelete();
            $table->boolean('mic_ready')->default(false);
            $table->boolean('has_downloaded')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dub_player_states');
    }
};
