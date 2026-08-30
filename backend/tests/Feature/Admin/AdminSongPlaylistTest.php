<?php

namespace Tests\Feature\Admin;

use App\Jobs\SyncSongPool;
use App\Models\SeedPlaylist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_sync_now_queues_the_job(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->postJson('/api/admin/song-playlists/sync')->assertStatus(202);

        Queue::assertPushed(SyncSongPool::class);
    }
}
