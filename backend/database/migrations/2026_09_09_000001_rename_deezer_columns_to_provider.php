<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Songle's music provider changes from Deezer to Spotify (metadata +
 * popularity) plus Apple's iTunes Search API (preview audio) - Deezer now
 * blocks datacenter IPs at the Akamai edge, so it can't be reached from the
 * production host at all.
 *
 * Provider-specific column names become provider-neutral:
 *   songs.deezer_track_id   -> songs.provider_track_id      (now a Spotify id)
 *   songs.artist_deezer_id  -> songs.artist_provider_id
 *   songs.artist_fan_count  -> songs.artist_follower_count  (Spotify followers)
 *   dataset_tracks.deezer_track_id -> dataset_tracks.provider_track_id
 *
 * `songs` is a regenerable cache (re-seeded by `php artisan songs:sync`) and
 * is wiped, same as every prior breaking discovery-schema change.
 * `dataset_tracks` is user content, but every existing row holds a Deezer id
 * and an expired Deezer preview URL that can no longer be refreshed, so it is
 * wiped too - Workshop owners re-import from a Spotify playlist.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('songs')->delete();
        DB::table('dataset_tracks')->delete();

        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique(['deezer_track_id']);
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->renameColumn('deezer_track_id', 'provider_track_id');
            $table->renameColumn('artist_deezer_id', 'artist_provider_id');
            $table->renameColumn('artist_fan_count', 'artist_follower_count');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->unique('provider_track_id');
        });

        Schema::table('dataset_tracks', function (Blueprint $table) {
            $table->dropUnique(['dataset_id', 'deezer_track_id']);
        });

        Schema::table('dataset_tracks', function (Blueprint $table) {
            $table->renameColumn('deezer_track_id', 'provider_track_id');
        });

        Schema::table('dataset_tracks', function (Blueprint $table) {
            $table->unique(['dataset_id', 'provider_track_id']);
        });
    }

    public function down(): void
    {
        DB::table('songs')->delete();
        DB::table('dataset_tracks')->delete();

        Schema::table('dataset_tracks', function (Blueprint $table) {
            $table->dropUnique(['dataset_id', 'provider_track_id']);
        });

        Schema::table('dataset_tracks', function (Blueprint $table) {
            $table->renameColumn('provider_track_id', 'deezer_track_id');
        });

        Schema::table('dataset_tracks', function (Blueprint $table) {
            $table->unique(['dataset_id', 'deezer_track_id']);
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique(['provider_track_id']);
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->renameColumn('provider_track_id', 'deezer_track_id');
            $table->renameColumn('artist_provider_id', 'artist_deezer_id');
            $table->renameColumn('artist_follower_count', 'artist_fan_count');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->unique('deezer_track_id');
        });
    }
};
