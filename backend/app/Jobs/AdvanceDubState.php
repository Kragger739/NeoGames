<?php

namespace App\Jobs;

use App\Services\DubGameService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched with a delay whenever a timed "Dub Together" phase opens (an
 * optional per-line recording window, or the watch auto-advance).
 * handleTimerExpired() branches on whichever state the dub_games row is
 * actually in when this fires; a stale fire (superseded by a player action
 * that already advanced) is a safe no-op via the state_version guard - same
 * pattern as AdvanceDdfGameState. Requires QUEUE_CONNECTION != "sync" and a
 * running worker. Assembling is NOT driven by this job - AssembleDubVideo
 * calls back into DubGameService directly.
 */
class AdvanceDubState implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $dubGameId,
        public int $expectedStateVersion,
    ) {}

    public function handle(DubGameService $service): void
    {
        $service->handleTimerExpired($this->dubGameId, $this->expectedStateVersion);
    }
}
