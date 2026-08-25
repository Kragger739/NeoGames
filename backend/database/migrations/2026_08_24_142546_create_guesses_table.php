<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('rounds')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('room_players')->cascadeOnDelete();
            $table->string('guess_text');
            $table->boolean('correct')->default(false);
            $table->decimal('snippet_stage_at_guess', 4, 1);
            $table->timestamps();

            $table->index(['round_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guesses');
    }
};
