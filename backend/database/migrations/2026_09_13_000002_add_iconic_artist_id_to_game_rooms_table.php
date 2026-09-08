<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a room as an Iconic Artist series run. When set, the lobby shows the
 * artist header and a trimmed settings form; gameplay itself is just a
 * genre=artist room with `artist_name` pinned to the curated act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->foreignId('iconic_artist_id')->nullable()->after('daily_challenge_id')
                ->constrained('iconic_artists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('iconic_artist_id');
        });
    }
};
