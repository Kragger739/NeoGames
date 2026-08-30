<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ddf_games', function (Blueprint $table) {
            $table->foreignId('current_turn_room_player_id')->nullable()->constrained('room_players')->nullOnDelete();
            $table->boolean('couch_mode')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('ddf_games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_turn_room_player_id');
            $table->dropColumn('couch_mode');
        });
    }
};
