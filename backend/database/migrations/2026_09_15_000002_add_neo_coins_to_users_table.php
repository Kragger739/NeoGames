<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NeoCoins wallet. A denormalized running balance kept in sync with the
 * neo_coin_events ledger (mirrors users.xp vs xp_events). Earned by levelling
 * up (config/neocoins.php), spendable in the Shop, grantable via the battle
 * pass and by an admin. Stripe top-ups later.
 *
 * Backfill: existing non-guest players get (level - 1) * per_level so a
 * long-time account isn't broke on launch day. level is the same closed form
 * LevelingService::levelForXp() uses, computed in PHP so it needs no SQLite
 * math extension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('neo_coins')->default(0)->after('xp');
        });

        $perLevel = (int) config('neocoins.per_level', 50);
        $coeff = (int) config('leveling.level_curve_coefficient', 50);
        $hasGuestFlag = Schema::hasColumn('users', 'is_guest');

        User::query()
            ->when($hasGuestFlag, fn ($q) => $q->where('is_guest', false))
            ->where('xp', '>', 0)
            ->select('id', 'xp')
            ->chunkById(500, function ($users) use ($perLevel, $coeff) {
                foreach ($users as $user) {
                    $level = (int) floor((1 + sqrt(1 + (4 * (int) $user->xp / $coeff))) / 2);
                    $coins = max(0, $level - 1) * $perLevel;

                    if ($coins > 0) {
                        DB::table('users')->where('id', $user->id)->update(['neo_coins' => $coins]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('neo_coins');
        });
    }
};
