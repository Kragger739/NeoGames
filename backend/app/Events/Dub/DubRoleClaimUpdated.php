<?php

namespace App\Events\Dub;

use App\Models\DubGame;
use App\Support\DubPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DubRoleClaimUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DubGame $game) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.role_claim_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'assignments' => DubPresenter::assignments($this->game),
            'players' => DubPresenter::players($this->game->room),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
