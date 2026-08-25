<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Region setting is being removed entirely - it never reliably scoped
 * discovery the way it was meant to (Deezer's curator-playlist trick was
 * unreliable in practice). songs is wiped rather than de-duplicated for the
 * new single-column unique index: the same track can currently have one
 * cached row per region, so several rows could collide on deezer_track_id
 * alone once region is gone - the cache regenerates on its own, same as
 * every prior breaking discovery-schema change this session.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('songs')->delete();

        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique(['deezer_track_id', 'region']);
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn('region');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->unique('deezer_track_id');
        });

        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->string('region')->default('global')->after('year_to');
        });

        DB::table('songs')->delete();

        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique(['deezer_track_id']);
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->string('region')->nullable()->after('genre');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->unique(['deezer_track_id', 'region']);
        });
    }
};
