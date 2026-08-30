<?php

namespace App\Support;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * The 6-digit "type the code from your inbox" email-verification flow.
 *
 * State lives in the cache, one entry per user id - no table, no model. The
 * entry carries the bcrypt hash of the code, a wrong-guess counter, and the
 * issue/expiry timestamps behind the 60s resend cooldown and the 15-minute
 * TTL. CACHE_STORE=database is adequate: the stock `cache` table persists the
 * entry well past 15 minutes and the payload is plain scalars.
 */
class EmailVerification
{
    private const COOLDOWN_SECONDS = 60;

    private const TTL_MINUTES = 15;

    private const MAX_ATTEMPTS = 5;

    /**
     * Issue a fresh code and email it. Overwrites any existing code for the
     * user and resets the attempt counter and the cooldown/TTL windows.
     */
    public static function issue(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = Carbon::now();
        $expiresAt = $now->copy()->addMinutes(self::TTL_MINUTES);

        Cache::put(self::key($user), [
            'hash' => Hash::make($code),
            'attempts' => 0,
            'issued_at' => $now->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        Mail::to($user->email)->send(new EmailVerificationCodeMail($code));
    }

    /**
     * Check a submitted code. Success marks the email verified and consumes
     * the code; a wrong guess burns one of MAX_ATTEMPTS (after which the code
     * is dropped); an absent or expired code just fails.
     */
    public static function verify(User $user, string $code): bool
    {
        $data = Cache::get(self::key($user));

        if ($data === null || Carbon::now()->getTimestamp() >= $data['expires_at']) {
            Cache::forget(self::key($user));

            return false;
        }

        $data['attempts']++;

        if ($data['attempts'] > self::MAX_ATTEMPTS) {
            Cache::forget(self::key($user));

            return false;
        }

        if (! Hash::check($code, $data['hash'])) {
            // Re-store with the burned attempt, keeping the ORIGINAL expiry so
            // a stream of wrong guesses can't slide the 15-minute window.
            Cache::put(self::key($user), $data, Carbon::createFromTimestamp($data['expires_at']));

            return false;
        }

        $user->markEmailAsVerified();
        Cache::forget(self::key($user));

        return true;
    }

    /**
     * Seconds the user must still wait before another code may be issued, or
     * 0 if they may resend now (or have no active code).
     */
    public static function cooldownRemaining(User $user): int
    {
        $data = Cache::get(self::key($user));

        if ($data === null) {
            return 0;
        }

        $elapsed = Carbon::now()->getTimestamp() - $data['issued_at'];

        return (int) max(0, self::COOLDOWN_SECONDS - $elapsed);
    }

    private static function key(User $user): string
    {
        return "email-verification:{$user->id}";
    }
}
