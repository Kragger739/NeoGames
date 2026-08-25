<?php

namespace App\Policies;

use App\Models\Friendship;
use App\Models\User;

class FriendshipPolicy
{
    /** Only the recipient of a pending request can accept it. */
    public function accept(User $user, Friendship $friendship): bool
    {
        return $friendship->status === 'pending' && $user->id === $friendship->friend_id;
    }

    /** Either party can remove a friendship - covers decline, cancel, and unfriend uniformly. */
    public function delete(User $user, Friendship $friendship): bool
    {
        return $user->id === $friendship->user_id || $user->id === $friendship->friend_id;
    }
}
