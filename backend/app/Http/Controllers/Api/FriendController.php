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

        return response()->json([
            'friends' => $friends->listFriends($user)->map(fn (User $u) => $this->presentUser($u)),
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
        ];
    }
}
