<?php

namespace App\Providers;

use App\Models\RoomPlayer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Anonymous room players are authenticated via a per-session token
        // header, entirely separate from host (Sanctum) auth. See
        // config/auth.php's "player" guard and routes/channels.php.
        Auth::viaRequest('player', function (Request $request) {
            $token = $request->header('X-Player-Token');

            if (! $token) {
                return null;
            }

            return RoomPlayer::where('connection_token', $token)->first();
        });
    }
}
