<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-season battlepass ladder, built in the admin dashboard. One row per
 * tier: the season XP needed to reach it, plus a free reward cosmetic and an
 * optional premium ("pass") reward. Supersedes the global
 * config('seasons.tier_thresholds') + cosmetics.tier convention; a season with
 * no rows here falls back to that legacy path (see SeasonService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('tier');            // 1-indexed ordinal
            $table->unsignedInteger('xp_threshold');        // cumulative season XP to reach it
            $table->foreignId('free_cosmetic_id')->nullable()->constrained('cosmetics')->nullOnDelete();
            $table->foreignId('premium_cosmetic_id')->nullable()->constrained('cosmetics')->nullOnDelete();
            $table->timestamps();

            $table->unique(['season_id', 'tier']);
            $table->index('season_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_tiers');
    }
};
