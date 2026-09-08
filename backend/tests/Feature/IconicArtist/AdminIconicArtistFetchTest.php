<?php

namespace Tests\Feature\IconicArtist;

use App\Jobs\FetchIconicArtistCatalogue;
use App\Models\IconicArtist;
use App\Models\IconicArtistSong;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminIconicArtistFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([FetchIconicArtistCatalogue::class]);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    public function test_adding_an_artist_kicks_off_a_catalogue_fetch(): void
    {
        $res = $this->actingAs($this->admin())
            ->post('/api/admin/iconic-artists', ['name' => 'Queen'])
            ->assertCreated()
            ->assertJsonPath('fetch_status', 'pending');

        Queue::assertPushed(FetchIconicArtistCatalogue::class);
        $this->assertNotNull(IconicArtist::find($res->json('id'))->fetch_run_token);
    }

    public function test_refetch_supersedes_and_returns_progress_fields(): void
    {
        $artist = IconicArtist::factory()->fetched(3)->create(['name' => 'Queen']);
        $before = $artist->fetch_run_token;

        $this->actingAs($this->admin())
            ->postJson("/api/admin/iconic-artists/{$artist->id}/refetch")
            ->assertOk()
            ->assertJsonStructure([
                'fetch_status', 'fetch_total', 'fetch_resolved', 'fetched_playable',
                'top20_count', 'fetch_error', 'fetched_at',
            ])
            ->assertJsonPath('fetch_status', 'pending');

        Queue::assertPushed(FetchIconicArtistCatalogue::class);
        $this->assertNotSame($before, $artist->fresh()->fetch_run_token);
    }

    public function test_update_re_fetches_only_when_the_name_changes(): void
    {
        $artist = IconicArtist::factory()->fetched(3)->create(['name' => 'Queen', 'sort_order' => 0]);
        $admin = $this->admin();

        $this->actingAs($admin)->post("/api/admin/iconic-artists/{$artist->id}", [
            'name' => 'Queen', 'sort_order' => 5,
        ])->assertOk();
        Queue::assertNotPushed(FetchIconicArtistCatalogue::class);

        $this->actingAs($admin)->post("/api/admin/iconic-artists/{$artist->id}", [
            'name' => 'Queen (UK)', 'sort_order' => 5,
        ])->assertOk();
        Queue::assertPushed(FetchIconicArtistCatalogue::class);
    }

    public function test_deleting_an_artist_cascades_link_rows_but_keeps_shared_songs(): void
    {
        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $song = Song::factory()->create();
        IconicArtistSong::factory()->create(['iconic_artist_id' => $artist->id, 'song_id' => $song->id]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/admin/iconic-artists/{$artist->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('iconic_artist_songs', ['iconic_artist_id' => $artist->id]);
        $this->assertDatabaseHas('songs', ['id' => $song->id]);
    }
}
