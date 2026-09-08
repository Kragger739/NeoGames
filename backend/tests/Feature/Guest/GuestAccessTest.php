<?php

namespace Tests\Feature\Guest;

use App\Jobs\AdvanceAfterReveal;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Jobs\FetchIconicArtistCatalogue;
use App\Models\GameRoom;
use App\Models\IconicArtist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuestAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class, AdvanceAfterReveal::class, FetchIconicArtistCatalogue::class]);
        $this->fakeDeezerTrackRefresh();
        Event::fake();
    }

    public function test_an_anonymous_visitor_can_start_the_daily_and_becomes_a_guest(): void
    {
        Song::factory()->count(12)->create(['genre' => 'iconic']);

        $this->postJson('/api/daily/start')->assertCreated();

        $guest = User::where('is_guest', true)->firstOrFail();
        $this->assertNull($guest->email);
        $this->assertNull($guest->password);
        $this->assertTrue($guest->hasVerifiedEmail());
        $this->assertSame(0, $guest->xp);
        $this->assertSame(0, $guest->neo_coins);
        $this->assertDatabaseHas('game_rooms', ['host_id' => $guest->id]);
    }

    public function test_an_anonymous_visitor_sees_only_free_artists_on_the_carousel(): void
    {
        IconicArtist::factory()->create(['name' => 'Free', 'price' => 0]);
        IconicArtist::factory()->create(['name' => 'Paid', 'price' => 500]);

        $this->getJson('/api/iconic-artists')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Free');
    }

    public function test_a_guest_cannot_reach_account_only_endpoints(): void
    {
        $guest = User::factory()->guest()->create();
        $artist = IconicArtist::factory()->create(['price' => 0]);

        $this->actingAs($guest)->postJson('/api/rooms')->assertForbidden();
        $this->actingAs($guest)->getJson('/api/leaderboard')->assertForbidden();
        $this->actingAs($guest)->getJson('/api/friends')->assertForbidden();
        $this->actingAs($guest)->getJson('/api/cosmetics')->assertForbidden();
        $this->actingAs($guest)->getJson('/api/shop')->assertForbidden();
        $this->actingAs($guest)->postJson("/api/iconic-artists/{$artist->id}/start")->assertForbidden();
    }

    public function test_a_guest_can_create_and_run_a_ddf_room(): void
    {
        $guest = User::factory()->guest()->create();

        $code = $this->actingAs($guest)->postJson('/api/ddf-rooms')->assertCreated()->json('code');

        $room = GameRoom::where('code', $code)->firstOrFail();
        $this->assertSame($guest->id, $room->host_id);

        // A GM-only read authorized purely by host_id match.
        $this->actingAs($guest)->getJson("/api/ddf-rooms/{$code}/gm-state")->assertOk();
    }
}
