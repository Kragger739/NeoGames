<?php

namespace App\Services;

use App\Models\Friendship;
use App\Models\GameRoom;
use App\Models\User;
use App\Notifications\RoomInviteNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

        return Friendship::create([
            'user_id' => $from->id,
            'friend_id' => $to->id,
            'status' => 'pending',
        ]);
    }

    public function accept(Friendship $friendship): void
    {
        $friendship->update(['status' => 'accepted']);
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
