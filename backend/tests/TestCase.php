<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Starting a round now re-confirms its song's preview is still live
     * (RoundService::pickPlayableSong() -> SongDiscoveryService::
     * ensurePlayable(), since Deezer's preview URLs are short-lived signed
     * links) - any test that starts a round therefore makes one real
     * GET /track/{id} call unless faked. This gives every game-flow test a
     * trivially-successful fake for that call, independent of whatever
     * else the test fakes (Http::fake() calls accumulate, matched in
     * registration order, so this is safe to call before or after a test's
     * own unrelated fakes as long as they don't also target /track/*).
     */
    protected function fakeDeezerTrackRefresh(): void
    {
        Http::fake([
            'api.deezer.com/track/*' => Http::response([
                'id' => 'refreshed',
                'title' => 'Refreshed Track',
                'artist' => ['name' => 'Some Artist'],
                'album' => ['cover_medium' => null],
                'preview' => 'https://example.com/refreshed-preview.mp3',
                'rank' => 500_000,
            ], 200),
        ]);
    }
}
