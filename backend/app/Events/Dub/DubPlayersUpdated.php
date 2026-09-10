<?php

namespace App\Events\Dub;

use App\Models\GameRoom;
use App\Support\DubPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Full roster + ready flags + this round's claims - replace wholesale. */
class DubPlayersUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameRoom $room) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.players_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'players' => DubPresenter::players($this->room),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
