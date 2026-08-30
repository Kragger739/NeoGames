<?php

namespace App\Events\Ddf;

use App\Models\DdfGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The correct_answer is deliberately never included here - only at
 * DdfQuestionResult, once submissions are locked.
 */
class DdfQuestionStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DdfGame $game) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.question_started';
    }

    public function broadcastWith(): array
    {
        return [
            'question_id' => $this->game->currentQuestion->id,
            'question_text' => $this->game->currentQuestion->text,
            'category' => $this->game->currentQuestion->category->value,
            'question_number' => $this->game->current_question_number,
            'rounds_played_this_cycle' => $this->game->rounds_played_this_cycle,
            'rounds_per_voting' => $this->game->rounds_per_voting,
            'timer_seconds' => $this->game->question_timer_seconds,
            'current_turn_room_player_id' => $this->game->current_turn_room_player_id,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
