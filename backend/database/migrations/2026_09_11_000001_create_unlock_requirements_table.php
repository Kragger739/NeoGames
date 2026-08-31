<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The player level required to host a game night, pick a game mode, or pick
 * a genre. One row per gate key ("game_night", "mode:battle_royale",
 * "genre:pop", ...). Editable from the admin dashboard so the progression
 * curve can be retuned without a redeploy - a missing key means "no lock"
 * (level 1). See App\Models\UnlockRequirement and App\Rules\RequiresUnlockLevel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unlock_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedTinyInteger('required_level')->default(1);
            $table->timestamps();
        });

        // Ships with only the pre-existing Battle Royale gate active; every
        // other key defaults to "no lock" (level 1). Tune from the admin
        // dashboard (/admin/unlocks).
        $now = now();
        $rows = [
            'game_night' => 1,

            'mode:classic' => 1,
            'mode:custom' => 1,
            'mode:battle_royale' => (int) config('leveling.battle_royale_min_level', 3),

            'genre:normal' => 1,
            'genre:pop' => 1,
            'genre:hip_hop' => 1,
            'genre:german_rap' => 1,
            'genre:classics' => 1,
            'genre:year' => 1,
            'genre:artist' => 1,
            'genre:multi_artist' => 1,
        ];

        DB::table('unlock_requirements')->insert(
            collect($rows)->map(fn ($level, $key) => [
                'key' => $key,
                'required_level' => $level,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all(),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('unlock_requirements');
    }
};
