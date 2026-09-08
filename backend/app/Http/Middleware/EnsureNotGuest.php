<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks guest users (EnsureGuestOrUser rows) from anything "linked to an
 * account": hosting, profile, cosmetics, leaderboard, friends, workshop, the
 * iconic-artist series, the Shop. `verified` does NOT cover this - a guest is
 * stamped email_verified_at = now() and passes it - so this is a separate,
 * explicit gate stacked alongside it.
 */
class EnsureNotGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_guest) {
            abort(403, 'Create a free account to use this.');
        }

        return $next($request);
    }
}
