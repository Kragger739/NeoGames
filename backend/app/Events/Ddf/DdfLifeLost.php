<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DdfLifeLost implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public GameRoom $room,
        public RoomPlayer $player,
        public int $heartsRemaining,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.life_lost';
    }

    public function broadcastWith(): array
    {
        return [
            'room_player_id' => $this->player->id,
            'hearts_remaining' => $this->heartsRemaining,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
