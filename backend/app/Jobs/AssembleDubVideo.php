<?php

namespace App\Jobs;

use App\Models\DubGame;
use App\Services\DubGameService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stitches the round's selected takes over the clip's music bed and muxes
 * the result with the video into one MP4.
 *
 * PHASE 1 (walking skeleton): no ffmpeg yet - it simply hands the clip's
 * own source video back as the "assembled" result so the whole
 * Recording -> Assembling -> Watch -> Rating loop is exercisable end to
 * end. Phase 5 replaces handle() with the real adelay/amix/mux pipeline;
 * the DubGameService::onAssemblyComplete / onAssemblyFailed contract stays
 * the same.
 */
class AssembleDubVideo implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $dubGameId,
        public int $roundNumber,
    ) {}

    public function handle(DubGameService $service): void
    {
        $game = DubGame::with('clip')->find($this->dubGameId);

        if (! $game || $game->round_number !== $this->roundNumber) {
            return; // superseded (restart / next round already moved on)
        }

        // Placeholder artefact: the untouched source video.
        $path = $game->clip?->source_video_path ?? 'dub/placeholder.mp4';

        $service->onAssemblyComplete($game, $path);
    }
}
