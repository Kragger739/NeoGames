<?php

namespace App\Events\Dub;

use App\Models\DubGame;
use App\Support\DubPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DubAssembled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DubGame $game) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.assembled';
    }

    public function broadcastWith(): array
    {
        return [
            'round_number' => $this->game->round_number,
            'assembled_video_url' => DubPresenter::url($this->game->assembled_video_path),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
