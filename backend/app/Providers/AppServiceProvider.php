<?php

namespace App\Providers;

use App\Models\RoomPlayer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        // Blanket per-client ceiling for every /api route (applied via
        // $middleware->throttleApi() in bootstrap/app.php).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Unconfigured Password::defaults() is just min(8). Tighten it in
        // production (kept lax in local/testing so factories/tests are fast).
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(12)->uncompromised()
            : Password::min(8));

        // Password-reset emails must land the user on the SPA, not a backend
        // URL - the API has no reset form of its own.
        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => rtrim(
            config('app.frontend_url') ?: config('app.url'),
            '/',
        )."/reset-password?token={$token}&email=".urlencode($notifiable->getEmailForPasswordReset()));

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

        // Discord isn't a Socialite built-in driver (unlike Google) - this
        // registers it via the community Socialite Providers extension
        // mechanism. See OAuthController for the actual redirect/callback.
        Event::listen(SocialiteWasCalled::class, DiscordExtendSocialite::class);
    }
}
