<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'username' => User::generateUniqueUsernameFrom($request->validated('name')),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        Auth::login($user);

        return response()->json($user, 201);
    }

    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials, remember: true)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

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
