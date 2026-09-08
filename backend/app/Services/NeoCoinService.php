<?php

namespace App\Services;

use App\Models\NeoCoinEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of users.neo_coins and neo_coin_events. Every method writes
 * a ledger row and moves the denormalized balance in the same transaction,
 * the same shape as LevelingService::award() does for xp / xp_events.
 */
class NeoCoinService
{
    /**
     * Unconditional credit (one ledger row per call). Use for movements that
     * genuinely happen every time - a Stripe purchase, a plain top-up.
     */
    public function credit(User $user, int $amount, string $reason, array $meta = []): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $amount, $reason, $meta) {
            NeoCoinEvent::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'reason' => $reason,
                'dedup_key' => null,
                'meta' => $meta ?: null,
            ]);

            User::where('id', $user->id)->increment('neo_coins', $amount);
        });
    }

    /**
     * Idempotent credit keyed by $dedupKey - a second call with the same key
     * is a no-op (returns false). Mirrors LevelingService::award()'s
     * insertOrIgnore + "only increment if a row was inserted" backbone.
     */
    public function creditOnce(User $user, int $amount, string $reason, string $dedupKey, array $meta = []): bool
    {
        if ($amount <= 0) {
            return false;
        }

        return DB::transaction(function () use ($user, $amount, $reason, $dedupKey, $meta) {
            // insertOrIgnore bypasses casts + timestamps, so meta is encoded
            // and created_at set explicitly (same as XpEvent).
            $inserted = NeoCoinEvent::query()->insertOrIgnore([
                'user_id' => $user->id,
                'amount' => $amount,
                'reason' => $reason,
                'dedup_key' => $dedupKey,
                'meta' => $meta ? json_encode($meta) : null,
                'created_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            User::where('id', $user->id)->increment('neo_coins', $amount);

            return true;
        });
    }

    /**
     * Spend. Race-safe: the WHERE guard makes decrement() affect 0 rows when
     * the balance is short, and the whole thing is one transaction, so two
     * concurrent buys can never overdraw. Returns false when the user can't
     * afford it (nothing written).
     */
    public function debit(User $user, int $amount, string $reason, array $meta = []): bool
    {
        if ($amount <= 0) {
            return false;
        }

        return DB::transaction(function () use ($user, $amount, $reason, $meta) {
            $affected = User::where('id', $user->id)
                ->where('neo_coins', '>=', $amount)
                ->decrement('neo_coins', $amount);

            if ($affected === 0) {
                return false;
            }

            NeoCoinEvent::create([
                'user_id' => $user->id,
                'amount' => -$amount,
                'reason' => $reason,
                'dedup_key' => null,
                'meta' => $meta ?: null,
            ]);

            return true;
        });
    }

    /**
     * Admin grant / deduct, clamped so the balance never goes negative. The
     * ledger records the delta that was actually applied after clamping.
     * Returns the new balance.
     */
    public function adminAdjust(User $user, int $delta, int $adminId): int
    {
        return DB::transaction(function () use ($user, $delta, $adminId) {
            $fresh = User::where('id', $user->id)->lockForUpdate()->first();
            $new = max(0, (int) $fresh->neo_coins + $delta);
            $applied = $new - (int) $fresh->neo_coins;

            User::where('id', $user->id)->update(['neo_coins' => $new]);

            NeoCoinEvent::create([
                'user_id' => $user->id,
                'amount' => $applied,
                'reason' => 'admin_grant',
                'dedup_key' => null,
                'meta' => ['admin_id' => $adminId, 'requested' => $delta],
            ]);

            return $new;
        });
    }
}
