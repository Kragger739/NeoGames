<?php

namespace App\Jobs;

use App\Services\RoundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Moves the game on from a resolved round once its reveal is over. Dispatched
 * twice for the same resolved round id - once with a REVEAL_DELAY_SECONDS
 * delay when the round resolves (the normal reveal wait), and once with no
 * delay if more than half the room votes to skip the reveal. RoundService's
 * advanced_at claim guarantees the advance runs exactly once; the later call
 * is a safe no-op.
 */
class AdvanceAfterReveal implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $resolvedRoundId) {}

    public function handle(RoundService $roundService): void
    {
        $roundService->advanceNow($this->resolvedRoundId);
    }
}
