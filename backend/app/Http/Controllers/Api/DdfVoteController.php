<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Services\DdfGameService;
use Illuminate\Http\Request;

class DdfVoteController extends Controller
{
    public function store(Request $request, string $code, DdfGameService $service)
    {
        $room = GameRoom::where('code', strtoupper($code))->where('game', 'ddf')->firstOrFail();
        $voter = $this->playerFor($request, $room);
        $target = $room->players()->findOrFail($request->input('target_room_player_id'));

        $service->castVote($room, $voter, $target);

        return response()->noContent();
    }

    private function playerFor(Request $request, GameRoom $room): RoomPlayer
    {
        $player = $request->user();

        if (! $player instanceof RoomPlayer || $player->room_id !== $room->id) {
            abort(403);
        }

        return $player;
    }
}
