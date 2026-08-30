<?php

namespace Tests\Feature\Song;

use App\Models\SeedPlaylist;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_it_seeds_the_pool_from_a_scraped_playlist_with_search_popularity_and_a_cached_preview(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Hit Song', 'Famous Act'], ['Obscure', 'Nobody']]);

        Http::fake([
            'audio-ssl.itunes.apple.com/*' => Http::response('fake-m4a-bytes', 200),
            'api.spotify.com/v1/search*' => function ($request) {
                if (! str_contains($request->url(), 'Hit%20Song') && ! str_contains(urldecode($request->url()), 'Hit Song')) {
                    return Http::response(['tracks' => ['items' => []]], 200);
                }

                return Http::response(['tracks' => ['items' => [[
                    'id' => 'sp-hit', 'name' => 'Hit Song', 'popularity' => 78, 'external_ids' => ['isrc' => 'X'],
                    'artists' => [['id' => 'art-1', 'name' => 'Famous Act']],
                    'album' => ['release_date' => '2012-03-04', 'images' => [['url' => 'https://img/a.jpg']]],
                ]]]], 200);
            },
            'itunes.apple.com/search*' => function ($request) {
                if (! str_contains(urldecode($request->url()), 'Famous Act')) {
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
            'preview_url' => '/storage/song-previews/sp-hit.m4a',
            'release_year' => 2012,
        ]);
        Storage::disk('public')->assertExists('song-previews/sp-hit.m4a');
        $this->assertDatabaseMissing('songs', ['title' => 'Obscure']);
        $this->assertSame(1, Song::count());
    }
}
