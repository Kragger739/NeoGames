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

    public function test_sync_now_queues_the_job_and_marks_it_queued(): void
    {
        Queue::fake();
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);

        $this->actingAs($this->admin())->postJson('/api/admin/song-playlists/sync')
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('last_sync.state', 'queued');

        Queue::assertPushed(SyncSongPool::class);
    }

    public function test_sync_now_is_rejected_with_no_playlists_and_no_german_rap_fallback(): void
    {
        Queue::fake();
        config(['music.german_rap_artists' => []]);

        $this->actingAs($this->admin())->postJson('/api/admin/song-playlists/sync')
            ->assertUnprocessable()->assertJsonValidationErrors('playlist');

        Queue::assertNothingPushed();
    }

    public function test_sync_now_does_not_double_queue_while_one_is_already_running(): void
    {
        Queue::fake();
        SeedPlaylist::create(['genre' => 'pop', 'spotify_playlist_id' => 'abcdefghijABCDEFGHIJ12']);
        \App\Console\Commands\SyncSongsCommand::putStatus('running');

        $this->actingAs($this->admin())->postJson('/api/admin/song-playlists/sync')
            ->assertStatus(202)->assertJsonPath('queued', false)->assertJsonPath('reason', 'already_running');

        Queue::assertNothingPushed();
    }
}
