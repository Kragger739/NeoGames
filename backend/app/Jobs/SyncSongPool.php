<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs `songs:sync` off the request thread - dispatched by the admin
 * dashboard's "Sync now" button and by the weekly schedule. The controller
 * refuses to queue a second one while the cached status still reads
 * queued/running, so concurrency is guarded there (transparently, with a
 * message) rather than by a silent unique-job drop.
 */
class SyncSongPool implements ShouldQueue
{
    use Queueable;

    /** A full sync is slow (throttled iTunes lookups); give it room. */
    public int $timeout = 3600;

    public function handle(): void
    {
        Artisan::call('songs:sync');
    }
}
