<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            // Nullable, same convention as genre: NULL means Global/unknown.
            $table->string('region')->nullable()->after('genre');
        });

        // The same track can now legitimately have one cache row per
        // region (its popularity differs by country) - the old
        // single-column unique index would let a later region's discovery
        // silently overwrite an earlier region's cached row for the same
        // track, so it's replaced with a composite one.
        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique(['itunes_track_id']);
            $table->unique(['itunes_track_id', 'region']);
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique(['itunes_track_id', 'region']);
            $table->unique('itunes_track_id');
            $table->dropColumn('region');
        });
    }
};
