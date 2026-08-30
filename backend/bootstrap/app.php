<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registered explicitly (rather than via withRouting's `channels:`
    // shortcut) so /broadcasting/auth accepts either a host (Sanctum) or a
    // room player (custom "player" guard) instead of only Sanctum.
    // "player" is checked first: an explicit X-Player-Token should win over
    // an incidental host session cookie (e.g. a host testing their own room
    // link in another tab of the same browser, which shares cookies).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth:player,sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Deployed behind a PaaS load balancer / Cloudflare tunnel (see
        // Dockerfile). Without this, Laravel sees plain HTTP: secure-cookie
        // flagging fails, generated URLs are http://, and per-IP rate
        // limiting keys on the proxy's address instead of the client's.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // A blanket ceiling on the whole API surface (the "api" rate
        // limiter is defined in AppServiceProvider). Auth-sensitive routes
        // (login, register, password reset, email verification) set their
        // own tighter throttles on top of this in routes/api.php.
        $middleware->throttleApi();

        // Route-level guards. Task 8 extends this same array.
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'not-banned' => \App\Http\Middleware\EnsureUserNotBanned::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
