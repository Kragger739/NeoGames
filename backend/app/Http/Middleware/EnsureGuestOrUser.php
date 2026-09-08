<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets an unauthenticated visitor through by minting a hidden guest `User`
 * row and logging it into the web session. Used only on the always-open
 * surfaces (Daily, Songle game-night entry, DDF create). A guest has no
 * email/password, earns no XP / level / NeoCoins (LevelingService skips
 * is_guest) and cannot host (EnsureNotGuest guards those routes). Registering
 * later upgrades this same row in place - see AuthController::register().
 *
 * StartSession is already on the api group (Sanctum's statefulApi()), so the
 * session row + Set-Cookie are persisted on the way out; the default guard is
 * `web`, so $request->user() resolves the guest for the rest of this request.
 */
class EnsureGuestOrUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            $guest = new User;
            $guest->forceFill([
                'name' => 'Guest '.Str::upper(Str::random(5)),
                'username' => null,
                'email' => null,
                'password' => null,
                'email_verified_at' => now(),
                'is_guest' => true,
            ])->save();

            Auth::guard('web')->login($guest);

            // Defensive: nothing in the ['guest-ok','not-banned'] group rebinds
            // the user resolver to another guard, but this keeps the guarantee
            // local rather than relying on that.
            $request->setUserResolver(fn () => $guest);
        }

        return $next($request);
    }
}
