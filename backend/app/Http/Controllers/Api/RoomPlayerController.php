<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\JoinRoomRequest;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use Illuminate\Validation\ValidationException;

class RoomPlayerController extends Controller
{
    public function store(JoinRoomRequest $request, string $code)
    {
        $room = GameRoom::where('code', strtoupper($code))->firstOrFail();
        $user = $request->user('sanctum');

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

        return response()->json([
            'id' => $player->id,
            'nickname' => $player->nickname,
            'connection_token' => $player->connection_token,
            'room_code' => $room->code,
        ], 201);
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
