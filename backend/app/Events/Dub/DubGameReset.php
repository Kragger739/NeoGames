<?php

namespace App\Events\Dub;

use App\Models\GameRoom;
use App\Support\DubPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DubGameReset implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameRoom $room) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.game_reset';
    }

    public function broadcastWith(): array
    {
        return [
            'players' => DubPresenter::players($this->room),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
