<?php

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Authorized by either a host (Sanctum) or a room player (custom "player"
 * guard) - see AppServiceProvider::boot() and config/auth.php.
 */
Broadcast::channel('room.{code}', function (User|RoomPlayer $authenticatable, string $code) {
    $room = GameRoom::where('code', $code)->first();

    if (! $room) {
        return false;
    }

    if ($authenticatable instanceof User) {
        return $authenticatable->id === $room->host_id
            ? ['id' => 'host', 'name' => $authenticatable->name]
            : false;
    }

    if ($authenticatable->room_id !== $room->id) {
        return false;
    }

    return ['id' => $authenticatable->id, 'name' => $authenticatable->nickname];
});

/**
 * One global presence channel every logged-in user joins on app load,
 * rather than a channel per friend pair - the frontend cross-references the
 * member roster against its own friends list to light up online dots.
 *
 * A RoomPlayer must be explicitly rejected here, not just excluded by a
 * bare User type-hint: Broadcast::routes resolves "auth:player,sanctum" in
 * that order (see bootstrap/app.php), and the frontend's axios interceptor
 * attaches any leftover X-Player-Token to every request, including ones
 * unrelated to a room. A host with a stale token would otherwise hit a
 * TypeError here instead of a clean rejection.
 */
Broadcast::channel('online-users', function (User|RoomPlayer $authenticatable) {
    if ($authenticatable instanceof RoomPlayer) {
        return false;
    }

    return ['id' => $authenticatable->id, 'name' => $authenticatable->name];
});

/**
 * Laravel's standard per-user private notification channel - powers live
 * room-invite delivery (see App\Notifications\RoomInviteNotification). Not
 * registered by default; must be declared explicitly. Same stale-token
 * rejection as above.
 */
Broadcast::channel('App.Models.User.{id}', function (User|RoomPlayer $authenticatable, int $id) {
    return $authenticatable instanceof User && $authenticatable->id === $id;
});
