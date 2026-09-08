<?php

namespace App\Events;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live tally of how many players have voted to skip the current reveal.
 * Counts only - never who voted.
 */
class RevealSkipProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public GameRoom $room,
        public int $roundId,
        public int $votesCast,
        public int $eligible,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'round.reveal_skip_progress';
    }

    public function broadcastWith(): array
    {
        return [
            'round_id' => $this->roundId,
            'votes_cast' => $this->votesCast,
            'eligible' => $this->eligible,
        ];
    }
}
