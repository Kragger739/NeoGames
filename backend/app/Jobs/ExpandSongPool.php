<?php

namespace App\Jobs;

use App\Enums\SongGenre;
use App\Models\Song;
use App\Services\SongDiscoveryService;
use App\Support\SongFilter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatched (fire-and-forget) whenever a round starts, so the local song
 * cache keeps growing in the background without any single round ever
 * blocking on a live iTunes/Last.fm search itself.
 */
class ExpandSongPool implements ShouldQueue
{
    use Queueable;

    public function __construct(public SongFilter $filter) {}

    public function handle(SongDiscoveryService $songDiscovery): void
    {
        // Cheap early-exit: most dispatches resolve instantly with zero
        // HTTP calls once a filter's pool is already healthy. Skipped for
        // Artist/MultiArtist - matchingFilter()'s global popularity band is
        // meaningless for these two (they rank relatively, see
        // SongDiscoveryService::relativeTierBucket()) - topUpTier()'s own
        // 24h per-artist freshness cache decides whether there's real work.
        $isRelative = in_array($this->filter->genre, [SongGenre::Artist, SongGenre::MultiArtist], true);

        if (! $isRelative && Song::query()->matchingFilter($this->filter)->count() >= config('songs.min_pool_size')) {
            return;
        }

        // Cache::lock's atomic acquire (not a plain existence check) closes
        // the race where several concurrently-dispatched jobs for the same
        // filter all pass the count check above before any of them records
        // the cooldown. Deliberately NOT the closure form (acquire-run-
        // release): the lock itself, left held until it expires after
        // expand_lock_seconds, IS the cooldown - a second run shortly after
        // fails to acquire and skips outright, rather than immediately
        // being free to run again once the first run's own work finishes.
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
