<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Google/Discord sign-in, alongside AuthController's email/password flow -
 * both end in the same place (an authenticated Sanctum session), so the
 * rest of the app never needs to know which path a host took to get there.
 *
 * redirect()/callback() are plain browser navigations, not XHR - the
 * provider's consent screen needs a full top-level page load, so these
 * return HTTP redirects, never JSON.
 *
 * The OAuth redirect_uri is built per-request (see resolveOrigin()) rather
 * than pinned to a single configured value, because this app is dev-proxied
 * through Vite - the backend always sees Host: localhost:8000 no matter
 * which public origin (localhost:5173 or a Cloudflare tunnel) the browser
 * is actually on. If redirect_uri always pointed at localhost:8000, a
 * browser that started the flow on the tunnel origin would get its session
 * cookie set for the tunnel domain, then get bounced back to localhost:8000
 * directly by the provider - a different origin that never received that
 * cookie, so Socialite's CSRF "state" check fails every time. Matching
 * redirect_uri to the origin that actually started the flow keeps both legs
 * on the same domain, so the cookie (and the session it carries) survives.
 */
class OAuthController extends Controller
{
    public function redirect(string $provider, Request $request)
    {
        $origin = $this->resolveOrigin($request);
        $request->session()->put('oauth_origin', $origin);

        return Socialite::driver($provider)
            ->redirectUrl($this->callbackUrl($origin, $provider))
            ->redirect();
    }

    public function callback(string $provider, Request $request)
    {
        // Falls back to the default frontend origin if the session didn't
        // carry over at all (e.g. cookies blocked) - matches this
        // controller's pre-existing behaviour for that edge case.
        $origin = $request->session()->pull('oauth_origin') ?? $this->defaultOrigin();

        try {
            $oauthUser = Socialite::driver($provider)
                ->redirectUrl($this->callbackUrl($origin, $provider))
                ->user();
        } catch (Throwable $e) {
            Log::warning('OAuth callback failed', [
                'provider' => $provider,
                'exception_class' => get_class($e),
                'exception' => $e->getMessage(),
            ]);

            return redirect($origin.'/login?error=oauth_failed');
        }

        // Matched by email, not provider+provider_id - a host who already
        // has a password account and then signs in with Google/Discord
        // using the same (necessarily verified-by-the-provider) email logs
        // into that same account instead of getting a duplicate one.
        $user = User::where('email', $oauthUser->getEmail())->first();

        if ($user) {
            $user->update([
                'provider' => $provider,
                'provider_id' => $oauthUser->getId(),
            ]);
        } else {
            $name = $oauthUser->getName() ?? $oauthUser->getNickname() ?? 'Player';

            $user = User::create([
                'name' => $name,
                'username' => User::generateUniqueUsernameFrom($name),
                'email' => $oauthUser->getEmail(),
                // Never actually used to log in (there's no password-login
                // path for an OAuth-only account) - just satisfies the
                // column's NOT NULL constraint without a schema change.
                'password' => Hash::make(Str::random(40)),
                'provider' => $provider,
                'provider_id' => $oauthUser->getId(),
            ]);

            // Seed the profile picture from the provider on first sign-up.
            // Best-effort: a missing/oversized/unreachable avatar just
            // leaves the account on the default, never blocks the login.
            if ($path = $this->fetchProviderAvatar($oauthUser->getAvatar())) {
                $user->update(['avatar_path' => $path]);
            }
        }

        // The provider proved ownership of this address. A brand-new account
        // is verified outright; an existing one that registered by email but
        // never entered its code is verified now - same "match by email"
        // trust the lookup above already relies on.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        // A banned account must not be able to slip in through the OAuth
        // path - mirror the password-login ban gate (LoginRequest) and the
        // `not-banned` middleware: don't authenticate, bounce back to the
        // SPA login with an error, same shape as the InvalidStateException
        // redirect above.
        if ($user->isBanned()) {
            return redirect($origin.'/login?error=banned');
        }

        // Same pairing AuthController::login() uses, so this session is
        // indistinguishable from a normal email/password login afterward.
        Auth::login($user, remember: true);
        Session::regenerate();

        return redirect($origin.'/');
    }

    private function callbackUrl(string $origin, string $provider): string
    {
        return $origin."/api/auth/{$provider}/callback";
    }

    /**
     * Download a provider avatar URL onto the 'public' disk and return its
     * relative path (the same shape ProfileController::updateAvatar()
     * stores, so avatarUrl() and purgeArtifacts() need no special-casing),
     * or null if anything at all goes wrong. Google returns a jpeg, Discord
     * a png/webp from its CDN; a user with no custom avatar, a slow host, a
     * non-image response, or an oversized file all resolve to null and the
     * caller simply skips setting avatar_path.
     */
    private function fetchProviderAvatar(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        try {
            $response = Http::timeout(5)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $ext = match ($response->header('Content-Type')) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => null,
            };

            // Mirror UpdateAvatarRequest's 2MB ceiling.
            if ($ext === null || strlen($response->body()) > 2 * 1024 * 1024) {
                return null;
            }

            $path = 'avatars/'.Str::uuid().'.'.$ext;
            Storage::disk('public')->put($path, $response->body());

            return $path;
        } catch (Throwable $e) {
            Log::warning('OAuth avatar fetch failed', [
                'exception_class' => get_class($e),
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Picks which configured FRONTEND_URL entry to build the OAuth
     * redirect_uri from. Prefers the explicit ?origin= query param the
     * frontend sends (window.location.origin - a JS-level fact with no
     * header-stripping/Referrer-Policy dependency), falling back to
     * matching the Referer header for any caller that doesn't send it,
     * then to the default origin if neither is present or matches.
     * Either way, only a value that exactly matches a configured origin is
     * ever trusted - an unrecognized ?origin= is simply ignored rather
     * than used to build a redirect.
     */
    private function resolveOrigin(Request $request): string
    {
        $candidates = array_filter([
            $request->query('origin'),
            $this->originFromReferer($request),
        ]);

        foreach ($candidates as $candidate) {
            foreach ($this->configuredOrigins() as $configured) {
                if ($configured === $candidate) {
                    return $configured;
                }
            }
        }

        return $this->defaultOrigin();
    }

    private function originFromReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! $referer) {
            return null;
        }

        $port = parse_url($referer, PHP_URL_PORT);

        return rtrim(parse_url($referer, PHP_URL_SCHEME).'://'.parse_url($referer, PHP_URL_HOST).($port ? ":{$port}" : ''), '/');
    }

    /**
     * Reuses config/cors.php's own FRONTEND_URL parsing (already split,
     * trimmed, and filtered there) rather than re-parsing the env var here.
     *
     * @return list<string>
     */
    private function configuredOrigins(): array
    {
        return array_values(config('cors.allowed_origins', []));
    }

    private function defaultOrigin(): string
    {
        return $this->configuredOrigins()[0] ?? 'http://localhost:5173';
    }
}
