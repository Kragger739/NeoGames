<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, EmailVerificationService $verification)
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'username' => User::generateUniqueUsernameFrom($request->validated('name')),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        Auth::login($user);

        // Fail-open: a transient SMTP hiccup must not 500 the registration.
        // The user lands on the verify screen and can hit "Resend" from there.
        try {
            $verification->sendCode($user);
        } catch (\Throwable $e) {
            Log::warning('Failed to send verification code on registration', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return response()->json($user, 201);
    }

    public function login(LoginRequest $request)
    {
        // Throttled (email+IP), honours an optional "remember" flag, and
        // regenerates the session on success - see LoginRequest.
        $request->authenticate();

        Session::regenerate();

        return response()->json(Auth::user());
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();

        return response()->noContent();
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }
}
