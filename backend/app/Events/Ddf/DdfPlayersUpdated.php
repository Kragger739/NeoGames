<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fires whenever the lobby's player roster or a ready flag changes -
 * broadcasts the full current list (not a delta) so a receiving client can
 * just replace its players array wholesale, correctly handling both "a new
 * player joined" (their own connection_token response has no live
 * broadcast counterpart) and "someone toggled ready" with one mechanism.
 */
class DdfPlayersUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameRoom $room) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.players_updated';
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
                    'is_eliminated' => $p->ddfState->is_eliminated,
                    'is_camera_ready' => $p->ddfState->is_camera_ready,
                    'level' => $p->level,
                ]),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
