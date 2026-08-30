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

    public function test_the_sync_walks_prepare_then_seed_to_done(): void
    {
        Storage::fake('public');
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        $this->fakeSpotifyToken();

        Http::fake([
            'api.spotify.com/v1/playlists/*/tracks*' => Http::response(['next' => null, 'items' => [
                ['track' => [
                    'id' => 'sp-a', 'name' => 'Song A', 'popularity' => 70, 'external_ids' => ['isrc' => 'X'],
                    'artists' => [['id' => 'art-1', 'name' => 'Act']],
                    'album' => ['release_date' => '2015', 'images' => [['url' => 'https://img/a.jpg']]],
                ]],
            ]], 200),
            'api.spotify.com/v1/artists*' => Http::response(['artists' => [['id' => 'art-1', 'followers' => ['total' => 999]]]], 200),
            'itunes.apple.com/search*' => Http::response(['results' => [[
                'kind' => 'song', 'trackId' => 1, 'trackName' => 'Song A', 'artistName' => 'Act',
                'previewUrl' => 'https://audio-ssl.itunes.apple.com/a.m4a',
                'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2015-01-01T00:00:00Z',
            ]]], 200),
            'audio-ssl.itunes.apple.com/*' => Http::response('m4a-bytes', 200),
        ]);

        $admin = $this->admin();
        $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync', ['start' => true]);
        $res->assertOk()->assertJsonPath('phase', 'prepare');

        // Advance until it settles.
        for ($i = 0; $i < 10; $i++) {
            $res = $this->actingAs($admin)->postJson('/api/admin/song-playlists/sync');
            if (in_array($res->json('phase'), ['done', 'error'], true)) {
                break;
            }
        }

        $res->assertJsonPath('phase', 'done')->assertJsonPath('seeded', 1);
        $this->assertDatabaseHas('songs', [
            'provider_track_id' => 'sp-a', 'genre' => 'pop', 'popularity' => 70,
            'preview_url' => '/storage/song-previews/sp-a.m4a', 'artist_follower_count' => 999,
        ]);
        $this->assertSame(1, Song::count());
    }
}
