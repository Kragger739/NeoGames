<?php

namespace App\Events\Dub;

use App\Models\DubGame;
use App\Support\DubPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DubLineAdvanced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DubGame $game) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.line_advanced';
    }

    public function broadcastWith(): array
    {
        $current = DubPresenter::currentLine($this->game);

        return [
            'current_line_index' => $this->game->current_line_index,
            'total_lines' => $this->game->clip?->lines()->count() ?? 0,
            'line' => $current['line'] ?? null,
            'assigned_room_player_id' => $current['assigned_room_player_id'] ?? null,
            'timer_seconds' => $this->game->line_timer_seconds,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
