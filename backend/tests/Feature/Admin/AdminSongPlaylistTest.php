<?php

namespace Tests\Feature\Admin;

use App\Models\SeedPlaylist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminSongPlaylistTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    public function test_non_admins_are_rejected(): void
    {
        $this->getJson('/api/admin/song-playlists')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/song-playlists')->assertForbidden();
    }

    public function test_an_admin_can_add_a_playlist_by_url_and_it_is_stored_by_id(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/admin/song-playlists', [
            'genre' => 'iconic',
            'playlist' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M?si=abc',
            'label' => 'All-time hits',
        ]);

        $res->assertCreated()->assertJsonPath('spotify_playlist_id', '37i9dQZF1DXcBWIGoYBM5M');
        $this->assertDatabaseHas('seed_playlists', [
            'genre' => 'iconic', 'spotify_playlist_id' => '37i9dQZF1DXcBWIGoYBM5M', 'label' => 'All-time hits',
        ]);
    }

    public function test_a_bad_playlist_reference_is_rejected(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/song-playlists', [
            'genre' => 'pop', 'playlist' => 'nonsense',
        ])->assertUnprocessable()->assertJsonValidationErrors('playlist');
    }

    public function test_an_artist_genre_is_rejected(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/song-playlists', [
            'genre' => 'artist', 'playlist' => 'abcdefghijABCDEFGHIJ12',
        ])->assertUnprocessable()->assertJsonValidationErrors('genre');
    }

    public function test_an_admin_can_remove_a_playlist(): void
    {
        $row = SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);

        $this->actingAs($this->admin())->deleteJson("/api/admin/song-playlists/{$row->id}")->assertNoContent();
        $this->assertDatabasemissing('seed_playlists', ['id' => $row->id]);
    }

    public function test_starting_a_sync_with_no_playlists_returns_the_error_phase(): void
    {
        $this->fakeSpotifyToken();

        $this->actingAs($this->admin())->postJson('/api/admin/song-playlists/sync', ['start' => true])
            ->assertOk()
            ->assertJsonPath('phase', 'error')
            ->assertJsonPath('error', 'No playlists configured - add at least one above, then sync again.');
    }

    public function test_starting_a_sync_without_spotify_credentials_errors(): void
    {
        config(['services.spotify.client_id' => null, 'services.spotify.client_secret' => null]);

        $this->actingAs($this->admin())->postJson('/api/admin/song-playlists/sync', ['start' => true])
            ->assertOk()->assertJsonPath('phase', 'error');
    }

    public function test_the_sync_scrapes_the_playlist_page_then_seeds_to_done(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Song A', 'Act']]);

        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [[
                'id' => 'sp-a', 'name' => 'Song A', 'popularity' => 70, 'external_ids' => ['isrc' => 'X'],
                'artists' => [['id' => 'art-1', 'name' => 'Act']],
                'album' => ['release_date' => '2015', 'images' => [['url' => 'https://img/a.jpg']]],
            ]]]], 200),
            'itunes.apple.com/search*' => Http::response(['results' => [[
                'kind' => 'song', 'trackId' => 1, 'trackName' => 'Song A', 'artistName' => 'Act',
                'previewUrl' => 'https://audio-ssl.itunes.apple.com/a.m4a',
                'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2015-01-01T00:00:00Z',
            ]]], 200),
            'audio-ssl.itunes.apple.com/*' => Http::response('m4a-bytes', 200),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync', ['start' => true])
            ->assertOk()->assertJsonPath('phase', 'prepare');

        $res = null;
        for ($i = 0; $i < 10; $i++) {
            $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
            if (in_array($res->json('phase'), ['done', 'error'], true)) {
                break;
            }
        }

        $res->assertJsonPath('phase', 'done')->assertJsonPath('seeded', 1);
        $this->assertDatabaseHas('songs', [
            'provider_track_id' => 'sp-a', 'genre' => 'pop', 'popularity' => 70,
            'preview_url' => '/storage/song-previews/sp-a.m4a',
        ]);
        $this->assertSame(1, Song::count());
    }

    public function test_a_scraped_track_still_seeds_when_spotify_search_is_unavailable(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Song B', 'Band']]);

        Http::fake([
            'api.spotify.com/v1/search*' => Http::response('<html>403</html>', 403, ['Content-Type' => 'text/html']),
            'itunes.apple.com/search*' => Http::response(['results' => [[
                'kind' => 'song', 'trackId' => 2, 'trackName' => 'Song B', 'artistName' => 'Band',
                'previewUrl' => 'https://audio-ssl.itunes.apple.com/b.m4a',
                'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2010-01-01T00:00:00Z',
            ]]], 200),
            'audio-ssl.itunes.apple.com/*' => Http::response('m4a', 200),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync', ['start' => true]);

        $res = null;
        for ($i = 0; $i < 10; $i++) {
            $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
            if (in_array($res->json('phase'), ['done', 'error'], true)) {
                break;
            }
        }

        $res->assertJsonPath('phase', 'done')->assertJsonPath('seeded', 1);
        $this->assertDatabaseHas('songs', ['title' => 'Song B', 'artist' => 'Band', 'genre' => 'pop']);
    }

    public function test_one_unreadable_playlist_does_not_sink_the_whole_sync(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'goodgoodgoodgoodgood12']);
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'blockedblockedblocked1']);
        $this->fakeSpotifyToken();

        Http::fake([
            'open.spotify.com/embed/playlist/blockedblockedblocked1' => Http::response('<html>nope</html>', 404),
            'open.spotify.com/embed/playlist/*' => Http::response(
                '<html><script id="__NEXT_DATA__" type="application/json">'
                .json_encode(['x' => [['title' => 'Song A', 'subtitle' => 'Act', 'uri' => 'spotify:track:1']]])
                .'</script></html>',
                200,
            ),
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => []]], 200),
            'itunes.apple.com/search*' => Http::response(['results' => [[
                'kind' => 'song', 'trackId' => 1, 'trackName' => 'Song A', 'artistName' => 'Act',
                'previewUrl' => 'https://audio-ssl.itunes.apple.com/a.m4a',
                'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2015-01-01T00:00:00Z',
            ]]], 200),
            'audio-ssl.itunes.apple.com/*' => Http::response('m4a', 200),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync', ['start' => true]);

        $res = null;
        for ($i = 0; $i < 12; $i++) {
            $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
            if (in_array($res->json('phase'), ['done', 'error'], true)) {
                break;
            }
        }

        $res->assertJsonPath('phase', 'done')
            ->assertJsonPath('seeded', 1)
            ->assertJsonPath('failed_playlists', ['blockedblockedblocked1']);
        $this->assertDatabaseHas('songs', ['title' => 'Song A']);
    }
}
