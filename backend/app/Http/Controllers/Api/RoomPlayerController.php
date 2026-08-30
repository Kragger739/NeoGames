<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Events\Ddf\DdfPlayersUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\JoinRoomRequest;
use App\Models\GameRoom;
use App\Models\Guess;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomPlayerController extends Controller
{
    public function store(JoinRoomRequest $request, string $code)
    {
        $room = GameRoom::where('code', strtoupper($code))->firstOrFail();
        $user = $request->user('sanctum');

        // The Game Master moderates "Der Dümmste fliegt" and is never a
        // contestant - unlike Songle's host, who's auto-seated as a player
        // below. Rejected here explicitly rather than silently falling
        // through to the reuse-existing-seat/create-seat logic, since a GM
        // revisiting their own room's join link is otherwise
        // indistinguishable from a host doing exactly that in Songle.
        if ($room->game === 'ddf' && $user && $user->id === $room->host_id) {
            throw ValidationException::withMessages([
                'nickname' => ["The Game Master doesn't join as a player."],
            ]);
        }

        // A logged-in visitor reopening a room they already have a seat in
        // (their own room's link, or an invite they clicked twice) gets
        // their existing seat back rather than an error or a duplicate -
        // this also lets them reconnect after the game has already started,
        // unlike an anonymous nickname-only join.
        if ($user) {
            $existing = $room->players()->where('user_id', $user->id)->first();

            if ($existing) {
                return response()->json([
                    'id' => $existing->id,
                    'nickname' => $existing->nickname,
                    'connection_token' => $existing->connection_token,
                    'room_code' => $room->code,
                ]);
            }
        }

        if ($room->status !== RoomStatus::Lobby) {
            throw ValidationException::withMessages([
                'nickname' => ['This room has already started and can no longer be joined.'],
            ]);
        }

        if ($room->player_mode === RoomPlayerMode::Solo && $room->players()->exists()) {
            throw ValidationException::withMessages([
                'nickname' => ['This is a solo room and cannot be joined by other players.'],
            ]);
        }

        if ($user) {
            // Logged-in join: skip the nickname form entirely, auto-suffix
            // on collision rather than blocking on one they didn't choose.
            $nickname = $this->uniqueNicknameFor($room, $user->username ?? $user->name);
        } else {
            $nickname = $request->validated('nickname');

            $exists = $room->players()
                ->whereRaw('lower(nickname) = ?', [mb_strtolower($nickname)])
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'nickname' => ['That nickname is already taken in this room.'],
                ]);
            }
        }

        $player = $room->players()->create([
            'user_id' => $user?->id,
            'nickname' => $nickname,
            'connection_token' => RoomPlayer::generateConnectionToken(),
            'score' => 0,
        ]);

        if ($room->game === 'ddf') {
            $player->ddfState()->create(['hearts' => 3]);
            broadcast(new DdfPlayersUpdated($room));
        }

        return response()->json([
            'id' => $player->id,
            'nickname' => $player->nickname,
            'connection_token' => $player->connection_token,
            'room_code' => $room->code,
            'game' => $room->game,
        ], 201);
    }

    /**
     * Removes the caller's own seat, then deletes the room entirely once
     * nobody is left in it (any status - lobby, active, or finished) -
     * "everyone left" is the one signal this app has for "nobody's coming
     * back to this room". Idempotent: leaving a room you're not seated in
     * (already left, stale tab, etc.) is a quiet no-op, not an error.
     */
    public function destroy(Request $request, string $code)
    {
        $room = GameRoom::where('code', strtoupper($code))->firstOrFail();
        $player = $this->seatFor($request, $room);

        if (! $player) {
            return response()->noContent();
        }

        DB::transaction(function () use ($room, $player) {
            // Locked for the rest of this transaction so two players
            // leaving at the same instant can't both observe a non-empty
            // room and both skip deletion, or both race to delete it.
            GameRoom::whereKey($room->id)->lockForUpdate()->first();

            $player->delete();

            if ($room->players()->doesntExist()) {
                $roundIds = $room->rounds()->pluck('id');
                Guess::whereIn('round_id', $roundIds)->delete();
                $room->rounds()->delete();
                $room->delete();
            }
        });

        return response()->noContent();
    }

    /**
     * Resolves the RoomPlayer row that corresponds to whichever guard
     * authenticated this request - a host's own seat (matched by user_id),
     * or the player-guard token's seat directly, same "either a host or a
     * room player" resolution routes/channels.php already does for the
     * room's broadcast channel.
     */
    private function seatFor(Request $request, GameRoom $room): ?RoomPlayer
    {
        $authenticatable = $request->user();

        if ($authenticatable instanceof User) {
            return $room->players()->where('user_id', $authenticatable->id)->first();
        }

        if ($authenticatable instanceof RoomPlayer && $authenticatable->room_id === $room->id) {
            return $authenticatable;
        }

        return null;
    }

    private function uniqueNicknameFor(GameRoom $room, string $base): string
    {
        $base = mb_substr($base, 0, 20);
        $nickname = $base;
        $suffix = 1;

        while ($room->players()->whereRaw('lower(nickname) = ?', [mb_strtolower($nickname)])->exists()) {
            $suffix++;
            $nickname = mb_substr($base, 0, 20 - mb_strlen((string) $suffix)).$suffix;
        }

        return $nickname;
    }
}
