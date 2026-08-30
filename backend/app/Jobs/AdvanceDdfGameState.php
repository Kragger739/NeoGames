<?php

namespace App\Jobs;

use App\Services\DdfGameService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched with a delay every time a timed DDF phase (the "get ready"
 * beat, a question's answer window, or a voting window) opens. Reused
 * across all three phases - handleTimerExpired() branches on whichever
 * state the ddf_games row is actually in when this fires, since only one
 * timed phase is ever live at once. Same version-guard pattern as
 * AdvanceRoundStage: a stale fire (superseded by an earlier manual advance
 * or an all-answered/all-voted completion) is a safe no-op. Requires
 * QUEUE_CONNECTION != "sync" and a running queue:listen/queue:work process.
 */
class AdvanceDdfGameState implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $ddfGameId,
        public int $expectedStateVersion,
    ) {}

    public function handle(DdfGameService $service): void
    {
        $service->handleTimerExpired($this->ddfGameId, $this->expectedStateVersion);
    }
}
