<?php

namespace App\Jobs;

use App\Services\RoundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched with a delay every time a round starts or advances a stage.
 * Requires QUEUE_CONNECTION to be something other than "sync" (which
 * ignores delay() entirely) and a supervised `queue:work`/`queue:listen`
 * process running.
 */
class AdvanceRoundStage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $roundId,
        public int $expectedStageVersion,
    ) {}

    public function handle(RoundService $roundService): void
    {
        $roundService->handleStageTimeout($this->roundId, $this->expectedStageVersion);
    }
}
