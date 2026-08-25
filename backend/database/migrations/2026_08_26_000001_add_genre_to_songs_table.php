<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            // Nullable, no backfill/truncation: unlike release_year (where
            // every row needed a value for the MIN_RELEASE_YEAR filter to
            // make sense), a NULL genre here just means "unknown" - it
            // naturally excludes old rows from Pop/Hip-hop matching without
            // needing to wipe the songs table, which by now also cascades
            // to real rounds/guesses history via cascadeOnDelete().
            $table->string('genre')->nullable()->after('release_year');
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn('genre');
        });
    }
};
