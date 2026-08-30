<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, EmailVerificationService $service)
    {
        // digits:6 keeps it a string - never cast, a leading-zero code must survive.
        $data = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        if (! $service->verify($request->user(), $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['That code is invalid or has expired.'],
            ]);
        }

        return response()->json($request->user()->fresh());
    }

    public function resend(Request $request, EmailVerificationService $service)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json($user);
        }

        // A VerificationCodeThrottledException here renders itself as 429
        // {message, retry_after}.
        $service->sendCode($user);

        return response()->json(['sent' => true]);
    }
}
