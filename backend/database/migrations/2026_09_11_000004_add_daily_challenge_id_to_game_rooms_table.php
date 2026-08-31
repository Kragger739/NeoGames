<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a room as a Daily-challenge run: when set, RoundService picks each
 * round's song straight from the challenge's fixed `song_ids` list instead
 * of the normal discovery path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->foreignId('daily_challenge_id')->nullable()->after('dataset_id')
                ->constrained('daily_challenges')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('daily_challenge_id');
        });
    }
};
