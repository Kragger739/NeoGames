<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->unsignedSmallInteger('release_year')->nullable()->after('popularity');
        });

        // Every existing row predates this column, so none can be trusted
        // to meet the new 2000+ bar - rather than leave a mixed,
        // half-verified pool, clear it. This is a local-dev cache table
        // that regenerates automatically via SongDiscoveryService /
        // ExpandSongPool; no user data is lost.
        DB::table('songs')->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn('release_year');
        });
    }
};
