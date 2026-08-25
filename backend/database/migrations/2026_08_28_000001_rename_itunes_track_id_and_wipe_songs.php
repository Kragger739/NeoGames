<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every existing row is an iTunes track ID with no Deezer
        // equivalent - useless post-switch, same as "scrap everything"
        // means for the discovery source itself.
        DB::table('songs')->delete();

        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique(['itunes_track_id', 'region']);
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->renameColumn('itunes_track_id', 'deezer_track_id');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->unique(['deezer_track_id', 'region']);
        });
    }

    public function down(): void
    {
        DB::table('songs')->delete();

        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique(['deezer_track_id', 'region']);
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->renameColumn('deezer_track_id', 'itunes_track_id');
        });

        Schema::table('songs', function (Blueprint $table) {
            $table->unique(['itunes_track_id', 'region']);
        });
    }
};
