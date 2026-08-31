<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row the first time a user starts a given day's Daily challenge - the
 * UNIQUE(daily_challenge_id, user_id) is what enforces "one attempt per
 * day". Result columns are filled in when the game finishes
 * (RoundService::finishGame).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_challenge_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('game_rooms')->nullOnDelete();
            $table->unsignedInteger('correct_count')->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['daily_challenge_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_challenge_attempts');
    }
};
