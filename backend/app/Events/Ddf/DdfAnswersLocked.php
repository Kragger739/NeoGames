<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Marks the Question -> AnswerSubmitted shift: disables input/timer client-side. */
class DdfAnswersLocked implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameRoom $room, public int $questionId) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.answers_locked';
    }

    public function broadcastWith(): array
    {
        return [
            'question_id' => $this->questionId,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
