<?php

namespace App\Events;

use App\Models\Round;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The song's title/artist are deliberately never included in the broadcast
 * payload - only the audio URL, stage, and a server timestamp for sync.
 */
class RoundStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Round $round) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->round->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'round.started';
    }

    public function broadcastWith(): array
    {
        return [
            'round_id' => $this->round->id,
            'audio_url' => $this->round->song->audioUrl(),
            'stage' => $this->round->snippet_stage,
            'tier' => $this->round->tier->value,
            'round_number' => $this->round->room->roundNumber(),
            'total_rounds' => $this->round->room->totalRounds(),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
