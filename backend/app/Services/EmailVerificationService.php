<?php

namespace App\Services;

use App\Exceptions\VerificationCodeThrottledException;
use App\Mail\EmailVerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Owns the 6-digit email verification code lifecycle: issuing a code (one
 * active row per user), emailing it, and checking a submitted code with a
 * capped number of attempts. Deliberately does NOT use Laravel's signed-URL
 * verification notification - the flow here is "type the code from your inbox".
 */
class EmailVerificationService
{
    private const COOLDOWN_SECONDS = 60;

    private const TTL_MINUTES = 15;

    private const MAX_ATTEMPTS = 5;

    /**
     * Issue a fresh code for the user and email it. Returns the plaintext
     * code (the controller hands it to the Mailable; tests use it to drive
     * verification).
     *
     * @throws VerificationCodeThrottledException if the previous code was
     *                                            issued less than the cooldown ago
     */
    public function sendCode(User $user): string
    {
        $existing = EmailVerificationCode::query()->where('user_id', $user->id)->first();

        if ($existing !== null) {
            $elapsed = $existing->created_at->diffInSeconds(now(), absolute: true);

            if ($elapsed < self::COOLDOWN_SECONDS) {
                throw new VerificationCodeThrottledException(
                    (int) ceil(self::COOLDOWN_SECONDS - $elapsed),
                );
            }
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ],
        );

        Mail::to($user->email)->send(new EmailVerificationCodeMail($code));

        return $code;
    }

    /**
     * Check a submitted code. On success the user's email is marked verified
     * and the code row is consumed. A wrong code burns an attempt; once the
     * cap is exceeded the row is dropped and the user must request a new code.
     */
    public function verify(User $user, string $code): bool
    {
        $row = EmailVerificationCode::query()->where('user_id', $user->id)->first();

        if ($row === null || $row->expires_at->isPast()) {
            return false;
        }

        $row->increment('attempts');

        if ($row->attempts > self::MAX_ATTEMPTS) {
            $row->delete();

            return false;
        }

        if (! Hash::check($code, $row->code_hash)) {
            return false;
        }

        $user->markEmailAsVerified();
        $row->delete();

        return true;
    }
}
