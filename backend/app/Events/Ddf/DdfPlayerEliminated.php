<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DdfPlayerEliminated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param 'hearts_zero'|'gm_removed' $reason */
    public function __construct(
        public GameRoom $room,
        public RoomPlayer $player,
        public string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.player_eliminated';
    }

    public function broadcastWith(): array
    {
        return [
            'room_player_id' => $this->player->id,
            'reason' => $this->reason,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
