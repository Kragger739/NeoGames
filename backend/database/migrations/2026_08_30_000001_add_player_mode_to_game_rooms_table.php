<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->string('player_mode')->default('multiplayer')->after('mode');
        });

        // 'solo' stops being a GameMode value (see GameMode::class) - any
        // existing room using it mechanically behaved like Classic's
        // win-condition already (see GuessService's "Classic + Solo"
        // comment), so remapping preserves its actual behavior rather than
        // leaving an enum cast to choke on a now-invalid 'mode' value.
        DB::table('game_rooms')->where('mode', 'solo')->update([
            'mode' => 'classic',
            'player_mode' => 'solo',
        ]);
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropColumn('player_mode');
        });
    }
};
