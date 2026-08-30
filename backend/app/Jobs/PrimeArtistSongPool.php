<?php

namespace App\Jobs;

use App\Services\SongDiscoveryService;
use App\Support\SongFilter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched (fire-and-forget) whenever a room's settings are saved with
 * genre Artist/MultiArtist, so the relative-popularity pool is usually warm
 * by the time the host clicks Start - RoundService::start() also calls
 * ensureArtistPoolReady() synchronously as a safety net for whatever this
 * doesn't finish in time.
 */
class PrimeArtistSongPool implements ShouldQueue
{
    use Queueable;

    public function __construct(public SongFilter $filter) {}

    public function handle(SongDiscoveryService $songDiscovery): void
    {
        $songDiscovery->ensureArtistPoolReady($this->filter);
    }
}
