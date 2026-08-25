<?php

namespace App\Events;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class GameFinished implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameRoom $room) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'game.finished';
    }

    public function broadcastWith(): array
    {
        return [
            'scoreboard' => $this->room->players()
                ->orderByDesc('score')
                ->get(['id', 'nickname', 'score', 'is_eliminated']),
        ];
    }
}
