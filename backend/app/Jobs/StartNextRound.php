<?php

namespace App\Jobs;

use App\Enums\RoomStatus;
use App\Models\GameRoom;
use App\Services\RoundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched with a short delay after a round resolves, so players see the
 * reveal before the next one starts.
 */
class StartNextRound implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $roomId) {}

    public function handle(RoundService $roundService): void
    {
        $room = GameRoom::find($this->roomId);

        if (! $room || $room->status !== RoomStatus::Active) {
            return;
        }

        if ($room->rounds()->where('status', 'playing')->exists()) {
            return;
        }

        $roundService->startNextRound($room);
    }
}
