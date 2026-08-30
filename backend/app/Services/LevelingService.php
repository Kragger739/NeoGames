<?php

namespace App\Services;

use App\Models\Round;
use App\Models\User;
use App\Models\XpEvent;
use Illuminate\Support\Facades\DB;

/**
 * Awards XP exactly once per game - when the whole game finishes, not per
 * individual round - based on final placement. Anonymous players (no
 * linked user_id) still occupy a placement slot (so a linked player who
 * finished behind them ranks accordingly) but never receive XP
 * themselves - not a limitation, the direct consequence of anonymous play
 * staying disconnected from any account.
 */
class LevelingService
{
    public function __construct(
        private SeasonService $seasons,
    ) {}

    /**
     * Called from RoundService::advanceAfterRoundResolved() only once the
     * game has actually finished (no next tier). $finalRound anchors the
     * XpEvent rows for round_id/room_id and the double-award-prevention
     * unique constraint, even though the award itself is game-wide, not
     * specific to that one round. Runs in its own transaction, separate
     * from the round-resolution write path itself: a leveling bug must
     * never be able to roll back or corrupt already-committed game state.
     */
    public function awardForGameFinish(Round $finalRound): void
    {
        $placements = $finalRound->room->players()
            ->orderByDesc('score')
            ->orderBy('id')
            ->get(['id', 'user_id']);

        if ($placements->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($finalRound, $placements) {
            foreach ($placements as $place => $player) {
                if ($player->user_id === null) {
                    continue;
                }

                [$type, $amount] = match ($place) {
                    0 => ['first', (int) config('leveling.xp_first')],
                    1 => ['second', (int) config('leveling.xp_second')],
                    2 => ['third', (int) config('leveling.xp_third')],
                    default => ['participation', (int) config('leveling.xp_participation')],
                };

                $this->award($player->user_id, $finalRound, $type, $amount);
            }
        });
    }

    /**
     * Pure function, no I/O - safe to call from anywhere (API responses,
     * tests) to render a user's current level from their stored xp.
     */
    public function levelForXp(int $xp): int
    {
        $coefficient = (int) config('leveling.level_curve_coefficient');

        return (int) floor((1 + sqrt(1 + (4 * $xp / $coefficient))) / 2);
    }

    private function award(int $userId, Round $round, string $type, int $amount): void
    {
        $inserted = XpEvent::query()->insertOrIgnore([
            'user_id' => $userId,
            'round_id' => $round->id,
            'room_id' => $round->room_id,
            'type' => $type,
            'amount' => $amount,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            // UNIQUE(round_id, user_id, type) already had this row - a
            // duplicate call for a round already awarded, safely no-op.
            return;
        }

        User::where('id', $userId)->increment('xp', $amount);

        // Season XP rides the same placement amounts and the same one-shot
        // guard above, so it can never be double-counted for a finished game.
        $this->seasons->awardSeasonXp($userId, $amount);
    }
}
