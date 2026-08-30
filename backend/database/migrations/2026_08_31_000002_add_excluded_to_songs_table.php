<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            // Manually blocked from ever being selected again (e.g. a
            // reported bad clip or a song someone asked removed from
            // rotation) - kept as a flag rather than a hard delete so
            // historical rounds still resolve their song relation, and
            // set independently of `genre` so it survives later
            // discovery/ExpandSongPool passes re-tagging the row (see
            // SongDiscoveryService::cache()'s monotonic genre tagging).
            $table->boolean('excluded')->default(false)->after('genre');
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropColumn('excluded');
        });
    }
};
