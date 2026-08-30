<?php

namespace App\Events\Ddf;

use App\Models\DdfGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * GM-only - the current question's correct answer, sent the moment the
 * question starts so the Game Master can grade without waiting for the
 * public DdfQuestionResult reveal. Never broadcast on the public room
 * channel; the .gm channel auth callback rejects every RoomPlayer.
 */
class DdfCorrectAnswerToGm implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DdfGame $game) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("room.{$this->game->room->code}.gm")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.gm.correct_answer';
    }

    public function broadcastWith(): array
    {
        return [
            'question_id' => $this->game->currentQuestion->id,
            'question_number' => $this->game->current_question_number,
            'correct_answer' => $this->game->currentQuestion->correct_answer,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
