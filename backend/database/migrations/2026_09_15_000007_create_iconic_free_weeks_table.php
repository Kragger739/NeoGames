<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per ISO week recording which priced iconic artist is the free
 * rotation pick for that week. Persisted (rather than recomputed from a
 * modulo) so the pick is stable for the whole week even when an admin adds,
 * disables, re-prices or reorders artists mid-week - it only ever changes on
 * the Monday boundary. Mirrors the daily_challenges "create once, read after"
 * idiom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iconic_free_weeks', function (Blueprint $table) {
            $table->id();
            $table->string('week_key')->unique(); // ISO year-week, e.g. "2026-W02"
            $table->foreignId('iconic_artist_id')->nullable()
                ->constrained('iconic_artists')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iconic_free_weeks');
    }
};
