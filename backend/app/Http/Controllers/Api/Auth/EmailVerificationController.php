<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Support\EmailVerification;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    /**
     * Confirm the 6-digit code from the user's inbox. Returns the refreshed
     * user (now email_verified) so the SPA can update its store in place.
     */
    public function verify(Request $request)
    {
        // digits:6 keeps it a string - a leading-zero code must survive.
        $data = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        if (! EmailVerification::verify($request->user(), $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['That code is invalid or has expired.'],
            ]);
        }

        return response()->json($request->user()->fresh());
    }

    /**
     * Email a fresh code. Still 200 (no-op) if already verified; 429 with
     * {message, retry_after} while the 60s cooldown is running.
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['sent' => true]);
        }

        $retryAfter = EmailVerification::cooldownRemaining($user);

        if ($retryAfter > 0) {
            return response()->json([
                'message' => 'Please wait before requesting another code.',
                'retry_after' => $retryAfter,
            ], 429, ['Retry-After' => $retryAfter]);
        }

        EmailVerification::issue($user);

        return response()->json(['sent' => true]);
    }
}
