<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Configure Spotify credentials and stub the client-credentials token
     * endpoint. Http::fake() accumulates, so a test can call this and then
     * add its own api.spotify.com endpoint fakes.
     */
    protected function fakeSpotifyToken(): void
    {
        config([
            'services.spotify.client_id' => 'test-id',
            'services.spotify.client_secret' => 'test-secret',
        ]);

        Http::fake([
            'accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], 200),
        ]);
    }

    /**
     * Fake the public Spotify embed playlist page (open.spotify.com) with an
     * embedded __NEXT_DATA__ payload SpotifyClient::scrapePlaylistItems can
     * walk. Http::fake() accumulates, so call this alongside other fakes.
     *
     * @param  array<int, array{0: string, 1: string}>  $tracks  [title, artist] pairs
     */
    protected function fakeSpotifyPlaylistPage(array $tracks): void
    {
        $items = [];
        foreach ($tracks as $i => [$title, $artist]) {
            $items[] = ['title' => $title, 'subtitle' => $artist, 'uri' => 'spotify:track:t'.$i];
        }

        $json = json_encode(['props' => ['pageProps' => ['state' => ['data' => ['entity' => [
            'title' => 'Test Playlist',
            'trackList' => $items,
        ]]]]]]);

        Http::fake([
            'open.spotify.com/embed/playlist/*' => Http::response(
                '<html><body><script id="__NEXT_DATA__" type="application/json">'.$json.'</script></body></html>',
                200,
            ),
        ]);
    }

    /**
     * Starting a round used to re-fetch its preview from Deezer (whose URLs
     * expired every ~15 min). The pool is now seeded from iTunes, whose
     * preview URLs are stable, so SongDiscoveryService::ensurePlayable() is
     * just a "row has a preview_url" check with no HTTP call. Kept as a
     * harmless no-op so existing game-flow tests that call it still read
     * clearly; new tests don't need it.
     */
    protected function fakeDeezerTrackRefresh(): void
    {
        // Intentionally empty - see docblock.
    }
}
