<?php

namespace Tests\Feature\IconicArtist;

use App\Events\RoomSettingsUpdated;
use App\Jobs\FetchIconicArtistCatalogue;
use App\Models\IconicArtist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FreeArtistOfTheWeekTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([FetchIconicArtistCatalogue::class]);
        Event::fake([RoomSettingsUpdated::class]);
    }

    /** @return array{0: IconicArtist, 1: IconicArtist, 2: IconicArtist} */
    private function threePricedArtists(): array
    {
        return [
            IconicArtist::factory()->create(['name' => 'Alpha', 'price' => 500, 'sort_order' => 1]),
            IconicArtist::factory()->create(['name' => 'Bravo', 'price' => 500, 'sort_order' => 2]),
            IconicArtist::factory()->create(['name' => 'Charlie', 'price' => 500, 'sort_order' => 3]),
        ];
    }

    public function test_it_rotates_through_the_priced_artists_by_week(): void
    {
        [$a, $b, $c] = $this->threePricedArtists();

        $this->travelTo('2026-01-05'); // Monday, week index % 3 == 0
        $this->assertSame($a->id, IconicArtist::freeThisWeek()->id);

        $this->travelTo('2026-01-12');
        $this->assertSame($b->id, IconicArtist::freeThisWeek()->id);

        $this->travelTo('2026-01-19');
        $this->assertSame($c->id, IconicArtist::freeThisWeek()->id);

        $this->travelTo('2026-01-26'); // wraps
        $this->assertSame($a->id, IconicArtist::freeThisWeek()->id);
    }

    public function test_the_pick_ignores_free_and_disabled_artists(): void
    {
        IconicArtist::factory()->create(['price' => 0]);              // permanently free
        IconicArtist::factory()->disabled()->create(['price' => 999]); // hidden
        $priced = IconicArtist::factory()->create(['price' => 400, 'sort_order' => 1]);

        $this->travelTo('2026-01-05');
        $this->assertSame($priced->id, IconicArtist::freeThisWeek()->id);
    }

    public function test_no_priced_artists_means_no_free_pick(): void
    {
        IconicArtist::factory()->create(['price' => 0]);

        $this->assertNull(IconicArtist::freeThisWeek());
    }

    public function test_the_weekly_artist_can_be_started_without_owning_it(): void
    {
        [$a, $b] = $this->threePricedArtists();
        $this->travelTo('2026-01-05'); // Alpha is free this week
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/iconic-artists/{$a->id}/start")
            ->assertCreated();

        // A different priced artist is still locked.
        $this->actingAs($user)
            ->postJson("/api/iconic-artists/{$b->id}/start")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('iconic');
    }

    public function test_access_is_temporal_and_grants_no_ownership(): void
    {
        [$a] = $this->threePricedArtists();
        $user = User::factory()->create();

        $this->travelTo('2026-01-05'); // Alpha free
        $this->actingAs($user)->postJson("/api/iconic-artists/{$a->id}/start")->assertCreated();
        $this->assertDatabaseMissing('iconic_artist_user', ['user_id' => $user->id, 'iconic_artist_id' => $a->id]);

        $this->travelTo('2026-01-12'); // Alpha no longer free, not owned
        $this->actingAs($user)
            ->postJson("/api/iconic-artists/{$a->id}/start")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('iconic');
    }

    public function test_the_carousel_includes_the_weekly_artist_and_flags_it(): void
    {
        [$a, $b] = $this->threePricedArtists();
        IconicArtist::factory()->create(['name' => 'Freebie', 'price' => 0, 'sort_order' => 0]);
        $this->travelTo('2026-01-05'); // Alpha free

        $names = $this->actingAs(User::factory()->create())
            ->getJson('/api/iconic-artists')
            ->assertOk()
            ->json();

        $byName = collect($names)->keyBy('name');
        $this->assertTrue($byName->has('Freebie'));
        $this->assertTrue($byName->has('Alpha'));
        $this->assertFalse($byName->has('Bravo')); // priced, not this week's pick
        $this->assertTrue($byName['Alpha']['free_this_week']);
        $this->assertFalse($byName['Freebie']['free_this_week']);
    }

    public function test_the_shop_marks_the_weekly_artist_and_returns_the_rollover_time(): void
    {
        [$a] = $this->threePricedArtists();
        $this->travelTo('2026-01-05');

        $body = $this->actingAs(User::factory()->create())
            ->getJson('/api/shop')
            ->assertOk()
            ->json();

        $this->assertSame('2026-01-12T00:00:00+00:00', $body['free_week_ends_at']);
        $flagged = collect($body['artists'])->where('free_this_week', true);
        $this->assertCount(1, $flagged);
        $this->assertSame($a->id, $flagged->first()['id']);
    }
}
