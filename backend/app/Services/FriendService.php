<?php

namespace App\Services;

use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Models\Friendship;
use App\Models\GameRoom;
use App\Models\User;
use App\Notifications\FriendRequestAcceptedNotification;
use App\Notifications\FriendRequestNotification;
use App\Notifications\RoomInviteNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FriendService
{
    public function sendRequest(User $from, User $to): Friendship
    {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['username' => ['You can\'t friend yourself.']]);
        }

        if ($this->areFriends($from, $to) || $this->pendingBetween($from, $to)) {
            throw ValidationException::withMessages(['username' => ['A friend request already exists between you two.']]);
        }

        $friendship = Friendship::create([
            'user_id' => $from->id,
            'friend_id' => $to->id,
            'status' => 'pending',
        ]);

        $to->notify(new FriendRequestNotification($from));

        return $friendship;
    }

    /**
     * Username typeahead for the "add a friend" box. Matches a substring of
     * the handle, and hides anyone the searcher is already connected to in
     * any way (accepted friend, or a pending request in either direction) so
     * every result is actionable. Capped at 8.
     *
     * @return Collection<int, User>
     */
    public function searchUsers(User $me, string $query): Collection
    {
        $connectedIds = Friendship::query()
            ->where('user_id', $me->id)
            ->orWhere('friend_id', $me->id)
            ->get(['user_id', 'friend_id'])
            ->flatMap(fn (Friendship $f) => [$f->user_id, $f->friend_id])
            ->push($me->id)
            ->unique()
            ->all();

        // Bindings are parameterised (injection-safe either way); escaping
        // %/_ just stops a literal one in the query acting as a wildcard.
        $term = addcslashes($query, '%_\\');

        return User::query()
            ->whereNotNull('username')
            ->where('username', 'like', "%{$term}%")
            ->whereNotIn('id', $connectedIds)
            ->orderBy('username')
            ->limit(8)
            ->get();
    }

    public function accept(Friendship $friendship): void
    {
        $friendship->update(['status' => 'accepted']);
        $friendship->user->notify(new FriendRequestAcceptedNotification($friendship->friend));
    }

    public function remove(Friendship $friendship): void
    {
        $friendship->delete();
    }

    public function areFriends(User $a, User $b): bool
    {
        return $this->between($a, $b)->where('status', 'accepted')->exists();
    }

    /**
     * Every accepted friendship involving $user, resolved to "the other
     * person" regardless of who originally sent the request.
     *
     * @return Collection<int, User>
     */
    public function listFriends(User $user): Collection
    {
        $friendships = Friendship::where('status', 'accepted')
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('friend_id', $user->id))
            ->with(['user', 'friend'])
            ->get();

        return $friendships
            ->map(fn (Friendship $f) => $f->user_id === $user->id ? $f->friend : $f->user)
            ->unique('id')
            ->values();
    }

    /**
     * @return array{incoming: Collection<int, Friendship>, outgoing: Collection<int, Friendship>}
     */
    public function listPending(User $user): array
    {
        return [
            'incoming' => Friendship::where('friend_id', $user->id)->where('status', 'pending')->with('user')->get(),
            'outgoing' => Friendship::where('user_id', $user->id)->where('status', 'pending')->with('friend')->get(),
        ];
    }

    /**
     * Batched "what joinable room is each of these users in right now"
     * lookup - one query for every friend on the page, not one per friend.
     * Only Lobby-status, Multiplayer rooms are joinable: a started/finished
     * room can't accept a new participant (RoomPlayerController::store()),
     * and a Solo room's one seat is already taken by the host from the
     * instant it's created (GameRoomController::store()), so it can never
     * be joined by anyone else either - excluding Solo up front avoids a
     * Join button that would just 422 on click.
     *
     * @param  SupportCollection<int, int>  $userIds
     * @return array<int, string> user_id => room code
     */
    public function currentRoomCodesFor(SupportCollection $userIds): array
    {
        if ($userIds->isEmpty()) {
            return [];
        }

        $rooms = GameRoom::where('status', RoomStatus::Lobby->value)
            ->where('player_mode', '!=', RoomPlayerMode::Solo->value)
            ->where(function ($q) use ($userIds) {
                $q->whereIn('host_id', $userIds)
                    ->orWhereHas('players', fn ($q2) => $q2->whereIn('user_id', $userIds));
            })
            ->with(['players' => fn ($q) => $q->whereIn('user_id', $userIds)])
            ->orderByDesc('id')
            ->get();

        $map = [];
        foreach ($rooms as $room) {
            if (in_array($room->host_id, $userIds->all(), true) && ! isset($map[$room->host_id])) {
                $map[$room->host_id] = $room->code;
            }
            foreach ($room->players as $player) {
                if ($player->user_id !== null && ! isset($map[$player->user_id])) {
                    $map[$player->user_id] = $room->code;
                }
            }
        }

        return $map;
    }

    public function inviteToRoom(User $from, User $to, GameRoom $room): void
    {
        if (! $this->areFriends($from, $to)) {
            throw ValidationException::withMessages(['friend_user_id' => ['You can only invite friends to your room.']]);
        }

        $seated = $room->players()->where('user_id', $from->id)->exists();

        if (! $seated) {
            throw new RuntimeException('Only a seated player in this room can send invites for it.');
        }

        $to->notify(new RoomInviteNotification($from, $room));
    }

    private function pendingBetween(User $a, User $b): bool
    {
        return $this->between($a, $b)->where('status', 'pending')->exists();
    }

    private function between(User $a, User $b): Builder
    {
        return Friendship::where(function ($q) use ($a, $b) {
            $q->where(fn ($q2) => $q2->where('user_id', $a->id)->where('friend_id', $b->id))
                ->orWhere(fn ($q2) => $q2->where('user_id', $b->id)->where('friend_id', $a->id));
        });
    }
}
