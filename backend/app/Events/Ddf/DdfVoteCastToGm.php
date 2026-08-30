<?php

namespace App\Events\Ddf;

use App\Models\DdfVote;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** GM-only - reveals each vote's target as it's cast, hidden from every player until DdfVotingResults. */
class DdfVoteCastToGm implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DdfVote $vote) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("room.{$this->vote->room->code}.gm")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.gm.vote_cast';
    }

    public function broadcastWith(): array
    {
        return [
            'voting_round_number' => $this->vote->voting_round_number,
            'voter_room_player_id' => $this->vote->voter_room_player_id,
            'target_room_player_id' => $this->vote->target_room_player_id,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
