<?php

namespace Tests\Feature\Admin;

use App\Models\SeedPlaylist;
use App\Models\Song;
use App\Models\User;
use App\Services\Music\IncrementalSongSync;
use App\Services\Music\SpotifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /** Clear both wait gates in the cached sync state so the next step() runs now. */
    private function rewindSyncClock(): void
    {
        $state = Cache::get(IncrementalSongSync::PROGRESS_KEY);

        if (is_array($state)) {
            $state['rate_limited_until'] = null;
            $state['throttle_until'] = null;
            Cache::put(IncrementalSongSync::PROGRESS_KEY, $state);
        }
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

    public function test_a_track_already_in_the_pool_is_not_re_synced(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        Song::factory()->create(['title' => 'Song A', 'artist' => 'Act']);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Song A', 'Act'], ['Song B', 'Act']]);
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => []]], 200),
            'itunes.apple.com/search*' => Http::response(['results' => [[
                'kind' => 'song', 'trackId' => 2, 'trackName' => 'Song B', 'artistName' => 'Act',
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

        $res->assertJsonPath('phase', 'done')
            ->assertJsonPath('seeded', 1)
            ->assertJsonPath('already', 1);
        Http::assertNotSent(fn ($r) => str_contains(urldecode($r->url()), 'Song A') && str_contains($r->url(), 'itunes'));
    }

    public function test_pooled_tracks_are_filtered_out_before_seeding(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        // 'Song A' matches an existing row by title+artist; 'Song C' matches by
        // synthetic scraped id. Only 'Song B' is new.
        Song::factory()->create(['title' => 'Song A', 'artist' => 'Act']);
        Song::factory()->create([
            'provider_track_id' => SpotifyClient::scrapedId('Act', 'Song C'),
            'title' => 'Different Text', 'artist' => 'Different Act',
        ]);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Song A', 'Act'], ['Song C', 'Act'], ['Song B', 'Act']]);
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => []]], 200),
            'itunes.apple.com/search*' => Http::response(['results' => [[
                'kind' => 'song', 'trackId' => 2, 'trackName' => 'Song B', 'artistName' => 'Act',
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

        $res->assertJsonPath('phase', 'done')
            ->assertJsonPath('seeded', 1)
            ->assertJsonPath('already', 2)
            ->assertJsonPath('total_items', 1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'itunes')
            && (str_contains(urldecode($r->url()), 'Song A') || str_contains(urldecode($r->url()), 'Song C')));
        $this->assertDatabaseHas('songs', ['title' => 'Song B', 'artist' => 'Act', 'genre' => 'pop']);
    }

    public function test_a_sync_where_every_track_is_already_pooled_completes_with_nothing_seeded(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        Song::factory()->create(['title' => 'Song A', 'artist' => 'Act']);
        Song::factory()->create(['title' => 'Song B', 'artist' => 'Act']);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Song A', 'Act'], ['Song B', 'Act']]);
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => []]], 200),
            'itunes.apple.com/search*' => Http::response(['results' => []], 200),
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

        $res->assertJsonPath('phase', 'done')
            ->assertJsonPath('seeded', 0)
            ->assertJsonPath('already', 2)
            ->assertJsonPath('total_items', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'itunes')
            || str_contains($r->url(), 'api.spotify.com/v1/search'));
        $this->assertSame(2, Song::count());
    }

    public function test_a_rate_limit_pauses_the_run_and_it_resumes_afterwards(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Song A', 'Act']]);

        // First search 429s, then (after we rewind the cooldown) succeeds.
        $searchCalls = 0;
        Http::fake([
            'api.spotify.com/v1/search*' => function () use (&$searchCalls) {
                $searchCalls++;

                return $searchCalls === 1
                    ? Http::response('slow down', 429, ['Retry-After' => '1'])
                    : Http::response(['tracks' => ['items' => []]], 200);
            },
            'itunes.apple.com/search*' => Http::response(['results' => [[
                'kind' => 'song', 'trackId' => 1, 'trackName' => 'Song A', 'artistName' => 'Act',
                'previewUrl' => 'https://audio-ssl.itunes.apple.com/a.m4a',
                'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2015-01-01T00:00:00Z',
            ]]], 200),
            'audio-ssl.itunes.apple.com/*' => Http::response('m4a', 200),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync', ['start' => true]);
        // prepare -> seed (hits 429, sets cooldown)
        $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
        $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
        $res->assertJsonPath('phase', 'seed');
        $this->assertNotNull($res->json('rate_limited_until'));

        // Simulate the cooldown elapsing.
        $state = Cache::get(IncrementalSongSync::PROGRESS_KEY);
        $state['rate_limited_until'] = time() - 1;
        Cache::put(IncrementalSongSync::PROGRESS_KEY, $state);

        for ($i = 0; $i < 5; $i++) {
            $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
            if (in_array($res->json('phase'), ['done', 'error'], true)) {
                break;
            }
        }

        $res->assertJsonPath('phase', 'done')->assertJsonPath('seeded', 1);
    }

    public function test_the_seed_phase_paces_itself_between_tracks(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Song A', 'Act'], ['Song B', 'Act']]);

        $itunesCalls = 0;
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => []]], 200),
            'itunes.apple.com/search*' => function () use (&$itunesCalls) {
                $itunesCalls++;

                return Http::response(['results' => [
                    ['kind' => 'song', 'trackId' => 1, 'trackName' => 'Song A', 'artistName' => 'Act',
                        'previewUrl' => 'https://audio-ssl.itunes.apple.com/a.m4a',
                        'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2015-01-01T00:00:00Z'],
                    ['kind' => 'song', 'trackId' => 2, 'trackName' => 'Song B', 'artistName' => 'Act',
                        'previewUrl' => 'https://audio-ssl.itunes.apple.com/b.m4a',
                        'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2015-01-01T00:00:00Z'],
                ]], 200);
            },
            'audio-ssl.itunes.apple.com/*' => Http::response('m4a', 200),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync', ['start' => true]);

        // Advance through prepare and seed exactly one track.
        $res = null;
        for ($i = 0; $i < 5; $i++) {
            $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
            if ($res->json('seeded') === 1) {
                break;
            }
        }

        $res->assertJsonPath('phase', 'seed')->assertJsonPath('seeded', 1);
        $this->assertGreaterThan(time(), $res->json('throttle_until'));
        $this->assertSame(1, $itunesCalls);

        // A step taken while the throttle window is still open does no work.
        $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
        $res->assertJsonPath('phase', 'seed')->assertJsonPath('seeded', 1);
        $this->assertSame(1, $itunesCalls, 'throttled step still hit iTunes');

        // Once the window passes, the next track seeds and the run finishes.
        $this->rewindSyncClock();
        for ($i = 0; $i < 5; $i++) {
            $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
            if (in_array($res->json('phase'), ['done', 'error'], true)) {
                break;
            }
            $this->rewindSyncClock();
        }

        $res->assertJsonPath('phase', 'done')->assertJsonPath('seeded', 2);
        $this->assertSame(2, $itunesCalls);
    }

    public function test_persistent_itunes_rate_limiting_makes_the_run_give_up(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Song A', 'Act'], ['Song B', 'Act'], ['Song C', 'Act']]);
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => []]], 200),
            'itunes.apple.com/search*' => Http::response('rate limited', 403),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync', ['start' => true]);

        $res = null;
        $terminated = false;
        for ($i = 0; $i < 25; $i++) {
            $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
            if (in_array($res->json('phase'), ['done', 'error'], true)) {
                $terminated = true;
                break;
            }
            $this->rewindSyncClock();
        }

        $this->assertTrue($terminated, 'the run never terminated');
        $res->assertJsonPath('phase', 'error')->assertJsonPath('seeded', 0);
        $this->assertStringContainsString('rate-limit', $res->json('error'));
        $this->assertStringContainsString('Sync again', $res->json('error'));
        $this->assertSame(0, Song::count());
    }

    public function test_rate_limit_strikes_reset_after_a_successful_seed(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage([['Song A', 'Act'], ['Song B', 'Act']]);

        // iTunes 403s the first two lookups, then serves matches.
        $itunesCalls = 0;
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => []]], 200),
            'itunes.apple.com/search*' => function () use (&$itunesCalls) {
                $itunesCalls++;

                if ($itunesCalls <= 2) {
                    return Http::response('slow down', 403);
                }

                return Http::response(['results' => [
                    ['kind' => 'song', 'trackId' => 1, 'trackName' => 'Song A', 'artistName' => 'Act',
                        'previewUrl' => 'https://audio-ssl.itunes.apple.com/a.m4a',
                        'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2015-01-01T00:00:00Z'],
                    ['kind' => 'song', 'trackId' => 2, 'trackName' => 'Song B', 'artistName' => 'Act',
                        'previewUrl' => 'https://audio-ssl.itunes.apple.com/b.m4a',
                        'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2015-01-01T00:00:00Z'],
                ]], 200);
            },
            'audio-ssl.itunes.apple.com/*' => Http::response('m4a', 200),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync', ['start' => true]);

        $res = null;
        for ($i = 0; $i < 25; $i++) {
            $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
            if (in_array($res->json('phase'), ['done', 'error'], true)) {
                break;
            }
            $this->rewindSyncClock();
        }

        $res->assertJsonPath('phase', 'done')->assertJsonPath('seeded', 2);
        $this->assertSame(2, Song::count());
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
