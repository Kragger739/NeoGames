<?php

namespace Tests\Feature\Song;

use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncSongsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['music.itunes_throttle_ms' => 0]);
    }

    public function test_it_fails_without_spotify_credentials(): void
    {
        config(['services.spotify.client_id' => null, 'services.spotify.client_secret' => null]);

        $this->artisan('songs:sync')->assertExitCode(1);
    }

    public function test_it_seeds_the_pool_from_a_playlist_with_spotify_popularity_and_an_itunes_preview(): void
    {
        config(['music.seed_playlists.pop' => ['abcdefghijABCDEFGHIJ12']]);
        $this->fakeSpotifyToken();

        Http::fake([
            'api.spotify.com/v1/playlists/*/tracks*' => Http::response(['next' => null, 'items' => [
                ['track' => [
                    'id' => 'sp-hit', 'name' => 'Hit Song', 'popularity' => 78,
                    'external_ids' => ['isrc' => 'X'],
                    'artists' => [['id' => 'art-1', 'name' => 'Famous Act']],
                    'album' => ['release_date' => '2012-03-04', 'images' => [['url' => 'https://img/a.jpg']]],
                ]],
                ['track' => [
                    'id' => 'sp-nopreview', 'name' => 'Obscure', 'popularity' => 12,
                    'external_ids' => ['isrc' => 'Y'],
                    'artists' => [['id' => 'art-2', 'name' => 'Nobody']],
                    'album' => ['release_date' => '2012', 'images' => []],
                ]],
            ]], 200),
            'api.spotify.com/v1/artists*' => Http::response(['artists' => [
                ['id' => 'art-1', 'followers' => ['total' => 3_000_000]],
                ['id' => 'art-2', 'followers' => ['total' => 40]],
            ]], 200),
            'itunes.apple.com/search*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

                // Only "Famous Act" resolves to a preview; "Nobody" doesn't.
                if (! str_contains((string) ($q['term'] ?? ''), 'Famous Act')) {
                    return Http::response(['results' => []], 200);
                }

                return Http::response(['results' => [[
                    'kind' => 'song', 'trackId' => 9, 'trackName' => 'Hit Song', 'artistName' => 'Famous Act',
                    'previewUrl' => 'https://audio-ssl.itunes.apple.com/hit.m4a',
                    'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg',
                    'releaseDate' => '2012-03-04T00:00:00Z',
                ]]], 200);
            },
        ]);

        $this->artisan('songs:sync --genre=pop')->assertExitCode(0);

        $this->assertDatabaseHas('songs', [
            'provider_track_id' => 'sp-hit',
            'genre' => 'pop',
            'popularity' => 78,
            'preview_url' => 'https://audio-ssl.itunes.apple.com/hit.m4a',
            'artist_follower_count' => 3_000_000,
            'release_year' => 2012,
        ]);
        $this->assertDatabaseMissing('songs', ['provider_track_id' => 'sp-nopreview']);
        $this->assertSame(1, Song::count());
    }
}
