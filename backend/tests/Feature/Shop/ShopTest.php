<?php

namespace Tests\Feature\Shop;

use App\Jobs\FetchIconicArtistCatalogue;
use App\Models\IconicArtist;
use App\Models\NeoCoinEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([FetchIconicArtistCatalogue::class]);
        Event::fake();
    }

    public function test_the_shop_lists_artists_with_price_and_ownership(): void
    {
        $free = IconicArtist::factory()->create(['name' => 'Free', 'price' => 0]);
        $paid = IconicArtist::factory()->create(['name' => 'Paid', 'price' => 500]);
        $user = User::factory()->create(['neo_coins' => 100]);
        $user->iconicArtists()->attach($paid->id, ['source' => 'shop', 'acquired_at' => now()]);

        $this->actingAs($user)->getJson('/api/shop')
            ->assertOk()
            ->assertJsonPath('neo_coins', 100)
            ->assertJsonPath('artists.0.owned', true)   // free
            ->assertJsonPath('artists.1.price', 500)
            ->assertJsonPath('artists.1.owned', true);  // attached
    }

    public function test_buying_a_priced_artist_debits_coins_and_unlocks_play(): void
    {
        $artist = IconicArtist::factory()->create(['price' => 500]);
        $user = User::factory()->create(['neo_coins' => 800]);

        $this->actingAs($user)
            ->postJson("/api/shop/iconic-artists/{$artist->id}/buy")
            ->assertOk()
            ->assertJsonPath('neo_coins', 300)
            ->assertJsonPath('owned', true);

        $this->assertDatabaseHas('iconic_artist_user', [
            'user_id' => $user->id, 'iconic_artist_id' => $artist->id, 'source' => 'shop',
        ]);
        $this->assertDatabaseHas('neo_coin_events', [
            'user_id' => $user->id, 'amount' => -500, 'reason' => 'spend',
        ]);

        $this->actingAs($user)
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->assertCreated();
    }

    public function test_buying_without_enough_coins_changes_nothing(): void
    {
        $artist = IconicArtist::factory()->create(['price' => 500]);
        $user = User::factory()->create(['neo_coins' => 100]);

        $this->actingAs($user)
            ->postJson("/api/shop/iconic-artists/{$artist->id}/buy")
            ->assertStatus(422)
            ->assertJsonValidationErrors('shop');

        $this->assertSame(100, $user->fresh()->neo_coins);
        $this->assertDatabaseMissing('iconic_artist_user', ['user_id' => $user->id]);
        $this->assertSame(0, NeoCoinEvent::where('user_id', $user->id)->count());
    }

    public function test_a_free_artist_cannot_be_bought_and_needs_no_purchase(): void
    {
        $artist = IconicArtist::factory()->create(['price' => 0]);
        $user = User::factory()->create(['neo_coins' => 800]);

        $this->actingAs($user)
            ->postJson("/api/shop/iconic-artists/{$artist->id}/buy")
            ->assertStatus(422)
            ->assertJsonValidationErrors('shop');

        $this->assertSame(800, $user->fresh()->neo_coins);
        $this->actingAs($user)
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->assertCreated();
    }

    public function test_buying_an_owned_artist_is_rejected(): void
    {
        $artist = IconicArtist::factory()->create(['price' => 500]);
        $user = User::factory()->create(['neo_coins' => 2000]);
        $user->iconicArtists()->attach($artist->id, ['source' => 'shop', 'acquired_at' => now()]);

        $this->actingAs($user)
            ->postJson("/api/shop/iconic-artists/{$artist->id}/buy")
            ->assertStatus(422)
            ->assertJsonValidationErrors('shop');

        $this->assertSame(2000, $user->fresh()->neo_coins);
    }
}
