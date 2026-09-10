<?php

namespace App\Events\Dub;

use App\Models\DubGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DubAssemblyStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DubGame $game) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.assembly_started';
    }

    public function broadcastWith(): array
    {
        return [
            'round_number' => $this->game->round_number,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
