<?php

namespace App\Events\Ddf;

use App\Models\DdfQuestion;
use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/**
 * The full reveal - safe to include correct_answer and every player's raw
 * answer_text now, since submissions are already locked (DdfAnswersLocked
 * fired first).
 */
class DdfQuestionResult implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param Collection<int, array{room_player_id: int, answer_text: ?string, is_correct: ?bool}> $results */
    public function __construct(
        public GameRoom $room,
        public DdfQuestion $question,
        public Collection $results,
        public bool $skipped,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.question_result';
    }

    public function broadcastWith(): array
    {
        return [
            'question_id' => $this->question->id,
            'correct_answer' => $this->question->correct_answer,
            'skipped' => $this->skipped,
            'results' => $this->results,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
