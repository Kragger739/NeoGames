<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            // A custom Songle dataset (imported Deezer playlist), or NULL for
            // the normal genre/year/artist-driven song selection. When set it
            // takes precedence and SongFilter::fromRoom() short-circuits to
            // dataset_tracks. nullOnDelete: dataset removed mid-game -> the
            // game falls back to normal selection.
            $table->foreignId('dataset_id')->nullable()->after('artist_names')->constrained('datasets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dataset_id');
        });
    }
};
