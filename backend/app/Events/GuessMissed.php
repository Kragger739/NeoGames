<?php

namespace App\Events;

use App\Models\Round;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notice-only: no answer is revealed and the round's stage is unaffected.
 */
class GuessMissed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Round $round, public string $nickname) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->round->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'round.guess_missed';
    }

    public function broadcastWith(): array
    {
        return [
            'round_id' => $this->round->id,
            'nickname' => $this->nickname,
        ];
    }
}
