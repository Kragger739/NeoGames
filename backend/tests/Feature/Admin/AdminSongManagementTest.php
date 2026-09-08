<?php

namespace Tests\Feature\Admin;

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSongManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    public function test_the_pool_list_is_gated_to_admins(): void
    {
        $this->getJson('/api/admin/songs')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/songs')
            ->assertForbidden();
    }

    public function test_it_paginates_and_reports_pool_counts(): void
    {
        Song::factory()->count(60)->create(['excluded' => false]);
        Song::factory()->count(4)->create(['excluded' => true]);

        $res = $this->actingAs($this->admin())->getJson('/api/admin/songs')->assertOk();

        $res->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 64)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.pool_size', 60)
            ->assertJsonPath('meta.removed_count', 4)
            ->assertJsonStructure([
                'data' => [['id', 'provider_track_id', 'title', 'artist', 'genre', 'popularity', 'release_year', 'excluded', 'last_used_at']],
                'meta' => ['current_page', 'last_page', 'total', 'pool_size', 'removed_count'],
                'genres',
            ]);
    }

    public function test_it_filters_by_status_genre_and_search(): void
    {
        Song::factory()->create(['title' => 'Blinding Lights', 'artist' => 'The Weeknd', 'genre' => 'pop', 'excluded' => false]);
        Song::factory()->create(['title' => 'Berlin Nights', 'artist' => 'Someone', 'genre' => 'german_rap', 'excluded' => false]);
        Song::factory()->create(['title' => 'Old Reject', 'artist' => 'Nobody', 'genre' => 'pop', 'excluded' => true]);

        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/admin/songs?status=removed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Old Reject');

        $this->actingAs($admin)->getJson('/api/admin/songs?status=in_pool&genre=pop')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Blinding Lights');

        $this->actingAs($admin)->getJson('/api/admin/songs?search=weeknd')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.artist', 'The Weeknd');

        // Matches the artist column too.
        $this->actingAs($admin)->getJson('/api/admin/songs?search=someone')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Berlin Nights');
    }

    public function test_an_admin_can_edit_song_metadata(): void
    {
        $song = Song::factory()->create([
            'title' => 'Typo Titel',
            'artist' => 'Wrong',
            'genre' => null,
            'popularity' => 40,
            'release_year' => 2010,
        ]);

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/songs/{$song->id}", [
                'title' => 'Correct Title',
                'artist' => 'Right Artist',
                'genre' => 'iconic',
                'popularity' => 88,
                'release_year' => 1999,
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Correct Title')
            ->assertJsonPath('genre', 'iconic');

        $this->assertDatabaseHas('songs', [
            'id' => $song->id,
            'title' => 'Correct Title',
            'artist' => 'Right Artist',
            'genre' => 'iconic',
            'popularity' => 88,
            'release_year' => 1999,
        ]);
    }

    public function test_it_rejects_invalid_metadata(): void
    {
        $song = Song::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/admin/songs/{$song->id}", ['popularity' => 150])
            ->assertUnprocessable();

        $this->actingAs($admin)->patchJson("/api/admin/songs/{$song->id}", ['genre' => 'reggae'])
            ->assertUnprocessable();

        $this->actingAs($admin)->patchJson("/api/admin/songs/{$song->id}", ['title' => ''])
            ->assertUnprocessable();
    }

    public function test_patch_removes_and_restores_a_song_via_the_excluded_flag(): void
    {
        $song = Song::factory()->create(['excluded' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/admin/songs/{$song->id}", ['excluded' => true])
            ->assertOk()->assertJsonPath('excluded', true);
        $this->assertDatabaseHas('songs', ['id' => $song->id, 'excluded' => true]);

        $this->actingAs($admin)->patchJson("/api/admin/songs/{$song->id}", ['excluded' => false])
            ->assertOk()->assertJsonPath('excluded', false);
        $this->assertDatabaseHas('songs', ['id' => $song->id, 'excluded' => false]);
    }

    public function test_an_excluded_song_is_gone_from_the_in_game_guess_pool(): void
    {
        $song = Song::factory()->create(['title' => 'On The Edge', 'artist' => 'Edgecase', 'popularity' => 90, 'excluded' => true]);

        $room = GameRoom::factory()->create();
        $player = $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=edgecase')
            ->assertOk()->assertJsonCount(0, 'results');

        $song->update(['excluded' => false]);

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=edgecase')
            ->assertOk()->assertJsonCount(1, 'results');
    }

    public function test_bulk_exclude_removes_many_songs_at_once(): void
    {
        $ids = Song::factory()->count(3)->create(['excluded' => false])->pluck('id')->all();
        $untouched = Song::factory()->create(['excluded' => false]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/songs/bulk-exclude', ['ids' => $ids, 'excluded' => true])
            ->assertOk()
            ->assertJsonPath('updated', 3);

        foreach ($ids as $id) {
            $this->assertDatabaseHas('songs', ['id' => $id, 'excluded' => true]);
        }
        $this->assertDatabaseHas('songs', ['id' => $untouched->id, 'excluded' => false]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/songs/bulk-exclude', ['ids' => $ids, 'excluded' => false])
            ->assertOk()
            ->assertJsonPath('updated', 3);

        $this->assertDatabaseHas('songs', ['id' => $ids[0], 'excluded' => false]);
    }

    public function test_bulk_exclude_validates_its_input(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/songs/bulk-exclude', ['ids' => [], 'excluded' => true])
            ->assertUnprocessable();

        $this->actingAs($admin)->postJson('/api/admin/songs/bulk-exclude', ['ids' => [999999], 'excluded' => true])
            ->assertUnprocessable();
    }
}
