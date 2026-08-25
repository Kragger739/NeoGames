<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('game_rooms')->cascadeOnDelete();
            $table->foreignId('song_id')->constrained('songs')->cascadeOnDelete();
            $table->string('tier');
            $table->decimal('snippet_stage', 4, 1);
            $table->timestamp('stage_started_at')->nullable();
            $table->string('status')->default('playing');
            $table->foreignId('winning_player_id')->nullable()->constrained('room_players')->nullOnDelete();
            $table->unsignedInteger('stage_version')->default(1);
            $table->timestamps();

            $table->index(['room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rounds');
    }
};
