<?php

namespace Tests\Feature\Music;

use App\Services\Music\SpotifyClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SpotifyClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSpotifyToken();
    }

    public function test_search_track_normalizes_the_response(): void
    {
        Http::fake(['api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [[
            'id' => 'sp-1',
            'name' => 'Take On Me',
            'popularity' => 84,
            'external_ids' => ['isrc' => 'NOAHA8500010'],
            'artists' => [['id' => 'a-1', 'name' => 'a-ha']],
            'album' => ['release_date' => '1985-06-01', 'images' => [['url' => 'https://img/1.jpg']]],
        ]]]], 200)]);

        $out = app(SpotifyClient::class)->searchTrack('take on me');

        $this->assertSame([
            'provider_track_id' => 'sp-1',
            'isrc' => 'NOAHA8500010',
            'title' => 'Take On Me',
            'artist' => 'a-ha',
            'artist_provider_id' => 'a-1',
            'album_art_url' => 'https://img/1.jpg',
            'popularity' => 84,
            'release_year' => 1985,
        ], $out[0]);
    }

    public function test_playlist_tracks_skips_removed_and_local_entries(): void
    {
        Http::fake(['api.spotify.com/v1/playlists/*/items*' => Http::response([
            'next' => null,
            'items' => [
                ['track' => null],
                ['track' => ['id' => 'x', 'name' => 'Local', 'is_local' => true, 'artists' => [['id' => 'a', 'name' => 'A']], 'album' => ['release_date' => '2000', 'images' => []], 'popularity' => 1]],
                ['track' => ['id' => 'keep', 'name' => 'Keep', 'artists' => [['id' => 'a', 'name' => 'A']], 'album' => ['release_date' => '2001', 'images' => []], 'popularity' => 50]],
            ],
        ], 200)]);

        $out = app(SpotifyClient::class)->playlistTracks('abcdefghijABCDEFGHIJ12');

        $this->assertCount(1, $out);
        $this->assertSame('keep', $out[0]['provider_track_id']);
    }

    public function test_find_artist_id_prefers_an_exact_name_match_with_the_most_followers(): void
    {
        Http::fake(['api.spotify.com/v1/search*' => Http::response(['artists' => ['items' => [
            ['id' => 'decoy', 'name' => 'Drake', 'images' => [], 'followers' => ['total' => 50]],
            ['id' => 'real', 'name' => 'Drake', 'images' => [], 'followers' => ['total' => 90_000_000]],
            ['id' => 'other', 'name' => 'Drake Bell', 'images' => [], 'followers' => ['total' => 99_000_000]],
        ]]], 200)]);

        $this->assertSame('real', app(SpotifyClient::class)->findArtistId('drake'));
    }

    public function test_a_429_raises_a_runtime_exception(): void
    {
        Http::fake(['api.spotify.com/v1/search*' => Http::response('slow down', 429, ['Retry-After' => '3'])]);

        $this->expectException(RuntimeException::class);
        app(SpotifyClient::class)->searchTrack('x');
    }

    public function test_scrape_playlist_items_reads_titles_and_artists_from_the_embed_page(): void
    {
        $this->fakeSpotifyPlaylistPage([['Bohemian Rhapsody', "Queen\u{00a0}"], ['Crazy In Love', 'Beyoncé, JAY-Z']]);

        $out = app(SpotifyClient::class)->scrapePlaylistItems('abcdefghijABCDEFGHIJ12');

        $this->assertSame(
            [['title' => 'Bohemian Rhapsody', 'artist' => 'Queen'], ['title' => 'Crazy In Love', 'artist' => 'Beyoncé']],
            $out,
        );
    }

    public function test_resolve_track_falls_back_to_a_synthetic_entry_when_search_is_blocked(): void
    {
        $this->fakeSpotifyToken();
        Http::fake(['api.spotify.com/v1/search*' => Http::response('<html>403</html>', 403, ['Content-Type' => 'text/html'])]);

        $track = app(SpotifyClient::class)->resolveTrack('Some Song', 'Some Band');

        $this->assertStringStartsWith('scraped:', $track['provider_track_id']);
        $this->assertSame('Some Song', $track['title']);
        $this->assertSame('Some Band', $track['artist']);
        $this->assertSame(60, $track['popularity']);
    }
}
