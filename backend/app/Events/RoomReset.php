<?php

namespace App\Events;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast when the host redoes a finished game, sending everyone still
 * on the results screen back to the lobby with scores cleared.
 */
class RoomReset implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameRoom $room) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'room.reset';
    }

    public function broadcastWith(): array
    {
        return [
            'players' => $this->room->players()
                ->orderByDesc('score')
                ->selectForSummary()
                ->get(),
        ];
    }
}
