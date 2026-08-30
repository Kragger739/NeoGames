<?php

namespace App\Services;

use App\Models\Cosmetic;
use App\Models\Season;
use App\Models\SeasonProgress;
use Illuminate\Support\Facades\DB;

/**
 * Seasonal progression: a per-season XP counter (earned alongside account XP on
 * game finish) that unlocks the season's free "track" cosmetics as it crosses
 * tier thresholds. Resets each season - a new Season row rolling in is the
 * reset, since progress and unlocks are scoped by season_id.
 *
 * Phase 2 will add a parallel paid "pass" reward per tier; this Phase 1 code
 * only ever grants source='track' cosmetics.
 */
class SeasonService
{
    /**
     * Add season XP for one user and grant any tier cosmetics they just
     * crossed. No-op when no season is active. Called once per linked player
     * from LevelingService::award(), guarded by the same
     * UNIQUE(round_id,user_id,type) idempotency - re-running a finished game
     * never double-awards.
     */
    public function awardSeasonXp(int $userId, int $accountXpAmount): void
    {
        $season = Season::current();

        if ($season === null) {
            return;
        }

        $amount = (int) round($accountXpAmount * (float) config('seasons.xp_multiplier'));

        if ($amount <= 0) {
            return;
        }

        $progress = SeasonProgress::firstOrCreate(
            ['season_id' => $season->id, 'user_id' => $userId],
            ['xp' => 0, 'current_tier' => 0],
        );

        $progress->increment('xp', $amount);

        $this->syncTierUnlocks($progress->refresh(), $season);
    }

    /**
     * Bring current_tier up to whatever the user's season XP now clears, and
     * grant every track cosmetic for the tiers newly crossed.
     */
    private function syncTierUnlocks(SeasonProgress $progress, Season $season): void
    {
        /** @var list<int> $thresholds */
        $thresholds = config('seasons.tier_thresholds');

        $reachedTier = 0;
        foreach ($thresholds as $index => $needed) {
            if ($progress->xp >= $needed) {
                $reachedTier = $index + 1;
            }
        }

        if ($reachedTier <= $progress->current_tier) {
            return;
        }

        $cosmeticIds = Cosmetic::query()
            ->where('season_id', $season->id)
            ->where('source', 'track')
            ->whereBetween('tier', [$progress->current_tier + 1, $reachedTier])
            ->pluck('id');

        foreach ($cosmeticIds as $cosmeticId) {
            DB::table('cosmetic_user')->insertOrIgnore([
                'user_id' => $progress->user_id,
                'cosmetic_id' => $cosmeticId,
                'source' => 'track',
                'acquired_at' => now(),
            ]);
        }

        $progress->update(['current_tier' => $reachedTier]);
    }
}
