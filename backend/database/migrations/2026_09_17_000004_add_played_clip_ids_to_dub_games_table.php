<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a dub room plays a Workshop pack, nextRound() auto-advances through
 * the pack's clips. `played_clip_ids` records which clips have been used
 * this game so the pack isn't repeated and the game finishes when it runs
 * out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dub_games', function (Blueprint $table) {
            $table->json('played_clip_ids')->nullable()->after('dub_clip_id');
        });
    }

    public function down(): void
    {
        Schema::table('dub_games', function (Blueprint $table) {
            $table->dropColumn('played_clip_ids');
        });
    }
};
