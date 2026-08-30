<?php

namespace App\Jobs;

use App\Services\SongDiscoveryService;
use App\Support\SongFilter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatched (fire-and-forget) whenever a round starts. For a fixed-playlist
 * genre the pool is owned by `php artisan songs:sync`, so there is nothing
 * to grow here - the job resolves instantly. For an Artist / MultiArtist
 * room it warms the per-room pool from the named act's Spotify top tracks,
 * so a lobby that just changed its artist(s) doesn't have to wait on the
 * synchronous safety net in RoundService::start().
 */
class ExpandSongPool implements ShouldQueue
{
    use Queueable;

    public function __construct(public SongFilter $filter) {}

    public function handle(SongDiscoveryService $songDiscovery): void
    {
        if (! $this->filter->genre->isArtistSourced()) {
            return;
        }

        // The lock IS the cooldown: left held until it expires after
        // expand_lock_seconds, so a second dispatch shortly after fails to
        // acquire and skips outright rather than re-warming immediately.
        $acquired = Cache::lock(
            "expand-song-pool:{$this->filter->cacheKey()}",
            (int) config('songs.expand_lock_seconds'),
        )->get();

        if (! $acquired) {
            return;
        }

        $songDiscovery->topUpTier($this->filter);
    }
}
