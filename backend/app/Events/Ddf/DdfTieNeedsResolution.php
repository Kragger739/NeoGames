<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** GM-only - fires when a revote is still tied and the game is blocked pending GM resolution. */
class DdfTieNeedsResolution implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param array<int, int> $tiedPlayerIds */
    public function __construct(public GameRoom $room, public array $tiedPlayerIds) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("room.{$this->room->code}.gm")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.gm.tie_needs_resolution';
    }

    public function broadcastWith(): array
    {
        return [
            'tied_player_ids' => $this->tiedPlayerIds,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
