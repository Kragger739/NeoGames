<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh the Songle song pool from the curated Spotify playlists once a
// week. iTunes preview URLs don't expire, so this is about picking up
// playlist changes, not keeping links alive. Runs long (throttled iTunes
// lookups) - overlap guard + background.
Schedule::command('songs:sync')->weeklyOn(1, '04:00')->withoutOverlapping()->runInBackground();
