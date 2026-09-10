<?php

namespace App\Events\Dub;

use App\Models\DubGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DubRoundScored implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<int, array{room_player_id: int, nickname: string, score: int}>  $breakdown */
    public function __construct(public DubGame $game, public array $breakdown) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.round_scored';
    }

    public function broadcastWith(): array
    {
        return [
            'round_number' => $this->game->round_number,
            'last_round_score' => $this->game->last_round_score,
            'total_score' => $this->game->total_score,
            'breakdown' => $this->breakdown,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
