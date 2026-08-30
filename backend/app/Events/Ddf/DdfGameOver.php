<?php

namespace App\Events\Ddf;

use App\Models\DdfGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DdfGameOver implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DdfGame $game) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.game_over';
    }

    public function broadcastWith(): array
    {
        return [
            'winner_room_player_id' => $this->game->winner_room_player_id,
            'winner_nickname' => $this->game->winner?->nickname,
            'players' => $this->game->room->players()
                ->selectForDdfSummary()
                ->get()
                ->map(fn ($p) => [
                    'room_player_id' => $p->id,
                    'nickname' => $p->nickname,
                    'hearts' => $p->ddfState->hearts,
                    'is_eliminated' => $p->ddfState->is_eliminated,
                ]),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
