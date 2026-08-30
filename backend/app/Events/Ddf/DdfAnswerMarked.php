<?php

namespace App\Events\Ddf;

use App\Models\DdfAnswer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Updates the live +/x badge without revealing the correct answer text. */
class DdfAnswerMarked implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DdfAnswer $answer) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->answer->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.answer_marked';
    }

    public function broadcastWith(): array
    {
        return [
            'room_player_id' => $this->answer->room_player_id,
            'is_correct' => $this->answer->is_correct,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
