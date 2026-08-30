<?php

use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\ArtistSearchController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\OAuthController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\DatasetController;
use App\Http\Controllers\Api\DdfAnswerController;
use App\Http\Controllers\Api\DdfGameController;
use App\Http\Controllers\Api\DdfVoteController;
use App\Http\Controllers\Api\FriendController;
use App\Http\Controllers\Api\GameRoomController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RoomInviteController;
use App\Http\Controllers\Api\RoomPlayerController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\SongSearchController;
use App\Http\Middleware\EnsureUserNotBanned;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Self-serve password recovery. sendResetLink() always answers 200 (no
// account enumeration); the reset email links back to the SPA.
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:6,1');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1');

// Safety net: Laravel's `verified` middleware redirects a non-JSON request
// to the route named "verification.notice". The SPA always sends
// Accept: application/json (so it gets a 403 instead), but a bare browser
// hit to a gated /api/* URL would 500 on a missing route without this.
Route::get('/email/verification-notice', fn () => response()->json([
    'message' => 'Your email address is not verified.',
], 403))->name('verification.notice');

// Forced onto the "web" session middleware, WITHOUT Sanctum's
// statefulApi() (still applied globally to every other api.php route),
// rather than stacking both. Sanctum's EnsureFrontendRequestsAreStateful
// only starts a session when the request's Referer/Origin matches a
// configured frontend domain - right for XHR calls from the SPA, but wrong
// here: these two routes are plain browser navigations, and the callback
// leg in particular is a top-level redirect landing here FROM the provider
// (Google/Discord), whose Referer reflects their own domain, not ours - so
// the conditional gate would never fire and the session (holding the OAuth
// state, and the originating origin - see OAuthController) would silently
// never exist. Layering "web"'s own unconditional session start ON TOP of
// Sanctum's (rather than instead of it) turned out to be worse than either
// alone: both start a session, independently, so a single request could
// produce two different session rows/cookies and whichever one the
// browser actually kept wasn't reliably the one Socialite wrote its CSRF
// state into - confirmed by finding two session rows created in the same
// second from one redirect() call while diagnosing a persistent
// InvalidStateException. withoutMiddleware() removes Sanctum's layer
// entirely for just these two routes, leaving exactly one StartSession.
Route::middleware('web')
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->group(function () {
        Route::get('/auth/{provider}/redirect', [OAuthController::class, 'redirect'])
            ->whereIn('provider', ['google', 'discord']);
        Route::get('/auth/{provider}/callback', [OAuthController::class, 'callback'])
            ->whereIn('provider', ['google', 'discord']);
    });

// Public: rooms are joined by code/nickname, no host login required.
Route::get('/rooms/{code}', [GameRoomController::class, 'show']);
Route::get('/rooms/{code}/song-history', [GameRoomController::class, 'songHistory']);
Route::post('/rooms/{code}/join', [RoomPlayerController::class, 'store']);

// "Der Dümmste fliegt" - the /rooms/{code}/join and /leave routes above are
// reused as-is (see RoomPlayerController's game==='ddf' hooks); everything
// else lives under its own /ddf-rooms prefix since the Game Master is
// never seated as a RoomPlayer, unlike Songle's host.
Route::get('/ddf-rooms/{code}', [DdfGameController::class, 'show']);

Route::middleware('auth:player')->group(function () {
    Route::patch('/ddf-rooms/{code}/ready', [DdfGameController::class, 'setReady']);
    Route::post('/ddf-rooms/{code}/answer', [DdfAnswerController::class, 'store']);
    Route::post('/ddf-rooms/{code}/vote', [DdfVoteController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::post('/ddf-rooms', [DdfGameController::class, 'store']);
    Route::get('/ddf-rooms/{code}/gm-state', [DdfGameController::class, 'gmState']);
    Route::post('/ddf-rooms/{code}/start', [DdfGameController::class, 'start']);
    Route::post('/ddf-rooms/{code}/pause', [DdfGameController::class, 'pause']);
    Route::post('/ddf-rooms/{code}/resume', [DdfGameController::class, 'resume']);
    Route::post('/ddf-rooms/{code}/next-question', [DdfGameController::class, 'nextQuestion']);
    Route::post('/ddf-rooms/{code}/reroll-question', [DdfGameController::class, 'rerollQuestion']);
    Route::patch('/ddf-rooms/{code}/settings', [DdfGameController::class, 'updateSettings']);
    Route::post('/ddf-rooms/{code}/players/{playerId}/mark', [DdfGameController::class, 'markAnswer']);
    Route::post('/ddf-rooms/{code}/skip-question', [DdfGameController::class, 'skipQuestion']);
    Route::post('/ddf-rooms/{code}/start-voting', [DdfGameController::class, 'startVoting']);
    Route::post('/ddf-rooms/{code}/end-voting', [DdfGameController::class, 'endVoting']);
    Route::post('/ddf-rooms/{code}/resolve-tie', [DdfGameController::class, 'resolveTie']);
    Route::post('/ddf-rooms/{code}/players/{playerId}/eliminate', [DdfGameController::class, 'eliminatePlayer']);
    Route::post('/ddf-rooms/{code}/restart', [DdfGameController::class, 'restart']);
    Route::post('/ddf-rooms/{code}/end', [DdfGameController::class, 'end']);
});

// Either a host (Sanctum) or a room player (custom "player" guard) can
// leave - same multi-guard pattern already used for /broadcasting/auth
// (see bootstrap/app.php).
Route::middleware('auth:player,sanctum')->delete('/rooms/{code}/leave', [RoomPlayerController::class, 'destroy']);

Route::middleware('auth:player')->group(function () {
    Route::post('/rounds/{round}/guess', [RoundController::class, 'guess']);
    Route::get('/songs/search', [SongSearchController::class, 'search']);
});

Route::middleware(['auth:sanctum', 'not-banned'])->group(function () {
    // A banned user must still be able to log out and to delete their own
    // account, so both are exempted from the `not-banned` gate.
    Route::post('/logout', [AuthController::class, 'logout'])
        ->withoutMiddleware(EnsureUserNotBanned::class);
    Route::get('/user', [AuthController::class, 'user']);

    // Account deletion stays outside the `verified` group: a user who can't
    // verify their email must still be able to delete the account.
    Route::delete('/user', [ProfileController::class, 'destroy'])
        ->withoutMiddleware(EnsureUserNotBanned::class);

    Route::get('/ping', function (Request $request) {
        return response()->json([
            'pong' => true,
            'authenticated' => $request->user() !== null,
        ]);
    });

    // Must stay reachable for a logged-in-but-unverified user, so these sit
    // OUTSIDE the `verified` group below - that's the whole point of them.
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:6,1');
    Route::post('/email/verification-code', [EmailVerificationController::class, 'resend'])->middleware('throttle:10,1');

    // Everything a real, verified host account can do. An unverified user
    // hitting any of these gets a 403 (JSON) from the `verified` middleware.
    Route::middleware('verified')->group(function () {
        Route::post('/rooms', [GameRoomController::class, 'store']);
        Route::patch('/rooms/{code}', [GameRoomController::class, 'update']);
        Route::get('/artists/search', [ArtistSearchController::class, 'search']);
        Route::post('/rooms/{code}/start', [GameRoomController::class, 'start']);
        Route::post('/rooms/{code}/redo', [GameRoomController::class, 'redo']);

        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar']);
        Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar']);
        Route::get('/cosmetics', [ProfileController::class, 'cosmetics']);
        Route::patch('/profile/cosmetics', [ProfileController::class, 'updateCosmetics']);

        Route::get('/leaderboard', [LeaderboardController::class, 'index']);

        // Workshop / Creator - custom datasets for DDF & Songle.
        Route::get('/datasets', [DatasetController::class, 'index']);
        Route::post('/datasets', [DatasetController::class, 'store']);
        Route::get('/datasets/{dataset}', [DatasetController::class, 'show']);
        Route::patch('/datasets/{dataset}', [DatasetController::class, 'update']);
        Route::delete('/datasets/{dataset}', [DatasetController::class, 'destroy']);
        Route::post('/datasets/{dataset}/duplicate', [DatasetController::class, 'duplicate']);
        Route::post('/datasets/{dataset}/questions', [DatasetController::class, 'storeQuestion']);
        Route::patch('/datasets/{dataset}/questions/reorder', [DatasetController::class, 'reorderQuestions']);
        Route::patch('/datasets/{dataset}/questions/{question}', [DatasetController::class, 'updateQuestion']);
        Route::delete('/datasets/{dataset}/questions/{question}', [DatasetController::class, 'destroyQuestion']);
        Route::post('/datasets/{dataset}/import', [DatasetController::class, 'importPlaylist']);
        Route::delete('/datasets/{dataset}/tracks/{track}', [DatasetController::class, 'destroyTrack']);

        Route::get('/friends/search', [FriendController::class, 'search']);
        Route::get('/friends', [FriendController::class, 'index']);
        Route::post('/friends', [FriendController::class, 'store']);
        Route::post('/friends/{friendship}/accept', [FriendController::class, 'accept']);
        Route::delete('/friends/{friendship}', [FriendController::class, 'destroy']);

        Route::post('/rooms/{code}/invite', [RoomInviteController::class, 'store']);
    });
});

// Admin dashboard. `admin` alias -> EnsureUserIsAdmin (bootstrap/app.php).
Route::middleware(['auth:sanctum', 'not-banned', 'verified', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::patch('/users/{user}', [AdminUserController::class, 'update']);
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
        Route::post('/users/{user}/ban', [AdminUserController::class, 'ban']);
        Route::post('/users/{user}/unban', [AdminUserController::class, 'unban']);
        Route::post('/users/{user}/reset-xp', [AdminUserController::class, 'resetXp']);
    });
