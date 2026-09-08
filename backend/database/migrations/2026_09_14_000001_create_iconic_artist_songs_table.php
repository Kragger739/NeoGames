<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The curated ~100-song catalogue for one Iconic Artist, fetched from
 * Spotify + iTunes when the artist is added. `rank` 1..N is popularity
 * order; an iconic game only ever plays `rank <= 20` (the hits), the rest
 * is a fallback reservoir. `song_id` links to a playable `songs` row once
 * its iTunes preview has been resolved; `nullOnDelete` so a `songs:sync
 * --fresh` wipe just makes the row look un-seeded again (a Re-fetch heals
 * it). The shared `songs` pool stays the regenerable play cache; this
 * table is the migration-stable source of truth for "which songs".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iconic_artist_songs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iconic_artist_id')->constrained('iconic_artists')->cascadeOnDelete();
            $table->string('provider_track_id');
            $table->string('title');
            $table->string('artist');
            $table->string('album_art_url')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedTinyInteger('popularity')->default(0);
            $table->unsignedInteger('rank')->nullable();
            $table->string('preview_url')->nullable();
            $table->foreignId('song_id')->nullable()->constrained('songs')->nullOnDelete();
            $table->boolean('unplayable')->default(false);
            $table->timestamps();

            $table->unique(['iconic_artist_id', 'provider_track_id']);
            $table->index(['iconic_artist_id', 'rank']);
            $table->index(['iconic_artist_id', 'song_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iconic_artist_songs');
    }
};
