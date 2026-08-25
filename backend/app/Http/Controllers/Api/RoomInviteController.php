<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendRoomInviteRequest;
use App\Models\GameRoom;
use App\Models\User;
use App\Services\FriendService;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RoomInviteController extends Controller
{
    public function store(SendRoomInviteRequest $request, string $code, FriendService $friends)
    {
        $room = GameRoom::where('code', strtoupper($code))->firstOrFail();
        $to = User::findOrFail($request->validated('friend_user_id'));

        try {
            $friends->inviteToRoom($request->user(), $to, $room);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['friend_user_id' => [$e->getMessage()]]);
        }

        return response()->noContent();
    }
}
