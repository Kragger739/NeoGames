<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Counts only - never who voted or for whom. */
class DdfVotingProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public GameRoom $room,
        public int $votesCast,
        public int $totalEligible,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.voting_progress';
    }

    public function broadcastWith(): array
    {
        return [
            'votes_cast' => $this->votesCast,
            'total_eligible' => $this->totalEligible,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
