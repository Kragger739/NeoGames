<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Never carries the answer text - just "someone answered" for the live status badges. */
class DdfPlayerAnswered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public GameRoom $room,
        public RoomPlayer $player,
        public bool $allAnswered,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.player_answered';
    }

    public function broadcastWith(): array
    {
        return [
            'room_player_id' => $this->player->id,
            'all_answered' => $this->allAnswered,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
