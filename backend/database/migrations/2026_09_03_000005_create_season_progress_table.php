<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Season XP is its own counter (not account xp) so it resets each
            // season. Earned 1:1 with placement XP on game finish - see
            // SeasonService::awardSeasonXp().
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedTinyInteger('current_tier')->default(0);
            $table->timestamps();

            $table->unique(['season_id', 'user_id']);
            // Drives GET /api/leaderboard: ORDER BY xp DESC within a season.
            $table->index(['season_id', 'xp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_progress');
    }
};
