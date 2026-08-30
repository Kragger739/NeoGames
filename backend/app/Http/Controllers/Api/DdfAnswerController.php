<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Services\DdfGameService;
use Illuminate\Http\Request;

class DdfAnswerController extends Controller
{
    public function store(Request $request, string $code, DdfGameService $service)
    {
        $room = GameRoom::where('code', strtoupper($code))->where('game', 'ddf')->firstOrFail();
        $player = $this->playerFor($request, $room);

        $service->submitAnswer($room, $player, (string) $request->input('answer_text', ''));

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
