<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DdfGameReset implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameRoom $room) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.game_reset';
    }

    public function broadcastWith(): array
    {
        return [
            'players' => $this->room->players()
                ->selectForDdfSummary()
                ->get()
                ->map(fn ($p) => [
                    'room_player_id' => $p->id,
                    'nickname' => $p->nickname,
                    'hearts' => $p->ddfState->hearts,
                    'is_eliminated' => false,
                ]),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
