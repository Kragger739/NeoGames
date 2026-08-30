<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DdfRoundComplete implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameRoom $room, public int $roundsPerVoting) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.round_complete';
    }

    public function broadcastWith(): array
    {
        return [
            'rounds_per_voting' => $this->roundsPerVoting,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
