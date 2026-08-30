<?php

namespace App\Events\Ddf;

use App\Models\DdfAnswer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * GM-only - carries the raw answer text as the player types/submits it, so
 * the GM can grade live before the public reveal. Never broadcast on the
 * public room channel.
 */
class DdfAnswerSubmittedToGm implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DdfAnswer $answer) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("room.{$this->answer->room->code}.gm")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.gm.answer_submitted';
    }

    public function broadcastWith(): array
    {
        return [
            'room_player_id' => $this->answer->room_player_id,
            'nickname' => $this->answer->player->nickname,
            'answer_text' => $this->answer->answer_text,
            'submitted_at' => $this->answer->submitted_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
