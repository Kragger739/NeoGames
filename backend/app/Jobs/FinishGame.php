<?php

namespace App\Jobs;

use App\Enums\RoomStatus;
use App\Models\GameRoom;
use App\Services\RoundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched with the same REVEAL_DELAY_SECONDS delay as StartNextRound, so
 * the final round of a game gets to sit on its reveal card for as long as
 * every other round instead of the room flipping to "finished" (and the
 * frontend navigating to /results) the instant it resolves.
 */
class FinishGame implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $roomId, public int $roundId) {}

    public function handle(RoundService $roundService): void
    {
        $room = GameRoom::find($this->roomId);

        if (! $room || $room->status !== RoomStatus::Active) {
            return;
        }

        $roundService->finishGame($room, $this->roundId);
    }
}
