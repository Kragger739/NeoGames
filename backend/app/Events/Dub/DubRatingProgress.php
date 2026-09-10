<?php

namespace App\Events\Dub;

use App\Models\DubGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DubRatingProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DubGame $game, public int $count, public int $total) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.rating_progress';
    }

    public function broadcastWith(): array
    {
        return [
            'count' => $this->count,
            'total' => $this->total,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
