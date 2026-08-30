<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs `songs:sync` off the request thread - dispatched by the admin
 * dashboard's "Sync now" button and by the weekly schedule. Unique so a
 * double-click or an overlapping schedule tick doesn't run two at once.
 */
class SyncSongPool implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A full sync is slow (throttled iTunes lookups); give it room. */
    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function handle(): void
    {
        Artisan::call('songs:sync');
    }

    public function uniqueId(): string
    {
        return 'songs-sync';
    }
}
