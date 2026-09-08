<?php

namespace App\Services;

use App\Models\Cosmetic;
use App\Models\Season;
use App\Models\SeasonProgress;
use App\Models\SeasonTier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seasonal progression: a per-season XP counter (earned alongside account XP on
 * game finish) that unlocks the season's battlepass rewards as it crosses tier
 * thresholds. Resets each season - a new Season row rolling in is the reset,
 * since progress and unlocks are scoped by season_id.
 *
 * The ladder comes from the season_tiers table (built in the admin dashboard):
 * every tier has a free reward and an optional premium reward, the latter
 * granted only to users who own that season's pass (season_progress.has_pass).
 * A season with no season_tiers rows falls back to the legacy
 * config('seasons.tier_thresholds') + cosmetics.tier convention.
 */
class SeasonService
{
    public function __construct(
        private NeoCoinService $neoCoins,
    ) {}

    /**
     * Add season XP for one user and grant any tier rewards they just crossed.
     * No-op when no season is active. Called once per linked player from
     * LevelingService::award(), guarded by the same UNIQUE(round_id,user_id,type)
     * idempotency - re-running a finished game never double-awards.
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
     * Grant a user this season's premium pass and back-fill every premium
     * reward for tiers they have already reached.
     */
    public function grantPass(int $userId, Season $season): void
    {
        $progress = SeasonProgress::firstOrCreate(
            ['season_id' => $season->id, 'user_id' => $userId],
            ['xp' => 0, 'current_tier' => 0],
        );

        $progress->update(['has_pass' => true]);

        $reachedTiers = $season->tiers()
            ->where('tier', '<=', $progress->current_tier)
            ->get();

        $this->grantCosmetics(
            $userId,
            $reachedTiers->pluck('premium_cosmetic_id')->filter()->all(),
            'pass',
        );

        // Back-fill premium coins + premium iconic-artist unlocks for tiers
        // already reached. creditOnce keys these by "bp:{season}:{tier}:premium",
        // so toggling the pass off and on never double-pays.
        $user = User::find($userId);

        foreach ($reachedTiers as $tier) {
            $this->grantPremiumTierExtras($user, $season, $tier);
        }
    }

    /**
     * Bring current_tier up to whatever the user's season XP now clears and
     * grant every reward for the tiers newly crossed.
     */
    private function syncTierUnlocks(SeasonProgress $progress, Season $season): void
    {
        $tiers = $season->tiers()->get();

        if ($tiers->isEmpty()) {
            $this->syncTierUnlocksLegacy($progress, $season);

            return;
        }

        $reachedTier = 0;

        foreach ($tiers as $tier) {
            if ($progress->xp >= $tier->xp_threshold) {
                $reachedTier = $tier->tier;
            }
        }

        if ($reachedTier <= $progress->current_tier) {
            return;
        }

        $crossed = $tiers->whereBetween('tier', [$progress->current_tier + 1, $reachedTier]);

        $free = $crossed->pluck('free_cosmetic_id')->filter()->all();
        $this->grantCosmetics($progress->user_id, $free, 'track');

        if ($progress->has_pass) {
            $premium = $crossed->pluck('premium_cosmetic_id')->filter()->all();
            $this->grantCosmetics($progress->user_id, $premium, 'pass');
        }

        $this->grantTierExtras($progress, $season, $crossed);

        $progress->update(['current_tier' => $reachedTier]);
    }

    /**
     * NeoCoins + iconic-artist unlocks for the tiers just crossed - the free
     * track always, the premium track only when the user owns the pass. All
     * idempotent (creditOnce dedup keys / insertOrIgnore), so re-entry from a
     * replayed game finish is harmless.
     *
     * @param  Collection<int, SeasonTier>  $crossed
     */
    private function grantTierExtras(SeasonProgress $progress, Season $season, Collection $crossed): void
    {
        if ($crossed->isEmpty()) {
            return;
        }

        $user = User::find($progress->user_id);

        foreach ($crossed as $tier) {
            $this->grantFreeTierExtras($user, $season, $tier);

            if ($progress->has_pass) {
                $this->grantPremiumTierExtras($user, $season, $tier);
            }
        }
    }

    private function grantFreeTierExtras(User $user, Season $season, SeasonTier $tier): void
    {
        if ((int) $tier->free_coins > 0) {
            $this->neoCoins->creditOnce(
                $user,
                (int) $tier->free_coins,
                'battlepass',
                "bp:{$season->id}:{$tier->tier}:free",
                ['season_id' => $season->id, 'tier' => $tier->tier, 'track' => 'free'],
            );
        }

        if ($tier->free_iconic_artist_id !== null) {
            $this->grantIconicArtist($user->id, (int) $tier->free_iconic_artist_id);
        }
    }

    private function grantPremiumTierExtras(User $user, Season $season, SeasonTier $tier): void
    {
        if ((int) $tier->premium_coins > 0) {
            $this->neoCoins->creditOnce(
                $user,
                (int) $tier->premium_coins,
                'battlepass',
                "bp:{$season->id}:{$tier->tier}:premium",
                ['season_id' => $season->id, 'tier' => $tier->tier, 'track' => 'premium'],
            );
        }

        if ($tier->premium_iconic_artist_id !== null) {
            $this->grantIconicArtist($user->id, (int) $tier->premium_iconic_artist_id);
        }
    }

    private function grantIconicArtist(int $userId, int $iconicArtistId): void
    {
        DB::table('iconic_artist_user')->insertOrIgnore([
            'user_id' => $userId,
            'iconic_artist_id' => $iconicArtistId,
            'source' => 'battlepass',
            'acquired_at' => now(),
        ]);
    }

    /**
     * Pre-season_tiers behaviour: thresholds from config, one reward per tier
     * pulled from the cosmetics table by (season_id, source='track', tier).
     * Cosmetic-only - NeoCoin / iconic-artist tier rewards need a real
     * season_tiers row and so never apply on the legacy path.
     */
    private function syncTierUnlocksLegacy(SeasonProgress $progress, Season $season): void
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
            ->pluck('id')
            ->all();

        $this->grantCosmetics($progress->user_id, $cosmeticIds, 'track');

        $progress->update(['current_tier' => $reachedTier]);
    }

    /**
     * @param  array<int, int>  $cosmeticIds
     */
    private function grantCosmetics(int $userId, array $cosmeticIds, string $source): void
    {
        foreach (array_unique($cosmeticIds) as $cosmeticId) {
            DB::table('cosmetic_user')->insertOrIgnore([
                'user_id' => $userId,
                'cosmetic_id' => $cosmeticId,
                'source' => $source,
                'acquired_at' => now(),
            ]);
        }
    }
}
