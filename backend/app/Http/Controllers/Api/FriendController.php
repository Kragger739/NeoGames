<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendFriendRequestRequest;
use App\Models\Friendship;
use App\Models\User;
use App\Services\FriendService;
use Illuminate\Http\Request;

class FriendController extends Controller
{
    public function index(Request $request, FriendService $friends)
    {
        $user = $request->user();
        $pending = $friends->listPending($user);
        $friendUsers = $friends->listFriends($user);
        $roomCodes = $friends->currentRoomCodesFor($friendUsers->pluck('id'));

        return response()->json([
            'friends' => $friendUsers->map(fn (User $u) => [
                ...$this->presentUser($u),
                'current_room_code' => $roomCodes[$u->id] ?? null,
            ]),
            'incoming_requests' => $pending['incoming']->map(fn (Friendship $f) => [
                'id' => $f->id,
                'user' => $this->presentUser($f->user),
            ]),
            'outgoing_requests' => $pending['outgoing']->map(fn (Friendship $f) => [
                'id' => $f->id,
                'user' => $this->presentUser($f->friend),
            ]),
        ]);
    }

    public function search(Request $request, FriendService $friends)
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:24'],
        ]);

        $matches = $friends->searchUsers($request->user(), $data['q']);

        return response()->json([
            'results' => $matches->map(fn (User $u) => $this->presentUser($u))->values(),
        ]);
    }

    public function store(SendFriendRequestRequest $request, FriendService $friends)
    {
        $to = User::where('username', $request->validated('username'))->firstOrFail();

        $friendship = $friends->sendRequest($request->user(), $to);

        return response()->json(['id' => $friendship->id], 201);
    }

    public function accept(Request $request, Friendship $friendship, FriendService $friends)
    {
        $this->authorize('accept', $friendship);

        $friends->accept($friendship);

        return response()->noContent();
    }

    public function destroy(Request $request, Friendship $friendship, FriendService $friends)
    {
        $this->authorize('delete', $friendship);

        $friends->remove($friendship);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username ?? $user->name,
            'level' => $user->level,
            'xp' => $user->xp,
            'avatar' => $user->avatarPayload(),
        ];
    }
}
