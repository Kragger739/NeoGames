<?php

namespace App\Events\Dub;

use App\Models\DubGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DubGameFinished implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<int, array{round_number: int, clip_title: string, score: float|null, video_url: string|null}>  $rounds */
    public function __construct(public DubGame $game, public array $rounds) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.game_finished';
    }

    public function broadcastWith(): array
    {
        return [
            'total_score' => $this->game->total_score,
            'rounds' => $this->rounds,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
