<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // { "frame": <cosmetic_id>, "hat": <id>, ... } - one id per slot,
            // null/absent = nothing equipped. Server validates every id against
            // ownership on save (see ProfileController::updateCosmetics). Same
            // json-column + array-cast pattern as game_rooms.enabled_tiers.
            $table->json('equipped_cosmetics')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('equipped_cosmetics');
        });
    }
};
