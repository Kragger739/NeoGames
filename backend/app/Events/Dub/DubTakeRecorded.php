<?php

namespace App\Events\Dub;

use App\Models\DubTake;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A take landed for a line - spectators use it to refresh the take feed. */
class DubTakeRecorded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DubTake $take, public string $roomCode) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->roomCode}")];
    }

    public function broadcastAs(): string
    {
        return 'dub.take_recorded';
    }

    public function broadcastWith(): array
    {
        return [
            'line_id' => $this->take->dub_clip_line_id,
            'room_player_id' => $this->take->room_player_id,
            'take_id' => $this->take->id,
            'duration_ms' => $this->take->duration_ms,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
