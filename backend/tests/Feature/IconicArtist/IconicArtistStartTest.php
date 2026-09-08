<?php

namespace Tests\Feature\IconicArtist;

use App\Events\RoomSettingsUpdated;
use App\Jobs\FetchIconicArtistCatalogue;
use App\Models\GameRoom;
use App\Models\IconicArtist;
use App\Models\UnlockRequirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IconicArtistStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([FetchIconicArtistCatalogue::class]);
        // RoomSettingsUpdated is ShouldBroadcastNow - without this the PATCH
        // tests try to reach a live Reverb server.
        Event::fake([RoomSettingsUpdated::class]);
    }

    public function test_the_carousel_lists_only_enabled_artists_sorted_by_order(): void
    {
        IconicArtist::factory()->create(['name' => 'Bee', 'sort_order' => 20]);
        IconicArtist::factory()->create(['name' => 'Ann', 'sort_order' => 10]);
        IconicArtist::factory()->disabled()->create(['name' => 'Hidden', 'sort_order' => 5]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/iconic-artists')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Ann')
            ->assertJsonPath('1.name', 'Bee');
    }

    public function test_the_carousel_is_open_to_guests(): void
    {
        IconicArtist::factory()->create(['name' => 'Free Act', 'price' => 0]);

        // guest-ok: an unauthenticated hit mints a hidden guest user and
        // returns the free-artist carousel. Picking one still needs an
        // account (covered in GuestAccessTest).
        $this->getJson('/api/iconic-artists')
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertDatabaseHas('users', ['is_guest' => true]);
    }

    public function test_a_priced_artist_the_user_does_not_own_cannot_be_started(): void
    {
        $artist = IconicArtist::factory()->create(['price' => 500]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('iconic');
    }

    public function test_starting_creates_an_artist_room_left_in_the_lobby(): void
    {
        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $user = User::factory()->create();

        $res = $this->actingAs($user)
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->assertCreated();

        $code = $res->json('code');
        $this->assertNotNull($code);
        $this->assertNotNull($res->json('player.connection_token'));

        $room = GameRoom::where('code', $code)->firstOrFail();
        $this->assertSame('lobby', $room->status->value);            // NOT started
        $this->assertSame('custom', $room->mode->value);
        $this->assertSame('solo', $room->player_mode->value);
        $this->assertSame('artist', $room->genre->value);
        $this->assertSame('Queen', $room->artist_name);
        $this->assertSame(5, $room->songs_per_tier);
        $this->assertSame(['easy'], $room->enabled_tiers);
        $this->assertSame($artist->id, $room->iconic_artist_id);
        $this->assertSame($user->id, $room->host_id);
        $this->assertDatabaseHas('room_players', ['room_id' => $room->id, 'user_id' => $user->id]);

        // The factory artist is 'pending' -> start() re-kicks the catalogue fetch.
        Queue::assertPushed(FetchIconicArtistCatalogue::class);
    }

    public function test_starting_does_not_re_fetch_an_already_fetched_artist(): void
    {
        $artist = IconicArtist::factory()->fetched(3)->create(['name' => 'Queen']);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->assertCreated();

        Queue::assertNotPushed(FetchIconicArtistCatalogue::class);
    }

    public function test_a_disabled_artist_cannot_be_started(): void
    {
        $artist = IconicArtist::factory()->disabled()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->assertNotFound();
    }

    public function test_a_free_artist_ignores_the_legacy_iconic_series_level_gate(): void
    {
        // The old iconic_series UnlockRequirement is retired - gating is now
        // NeoCoins-only (price), so a level-1 account can play any free act.
        UnlockRequirement::updateOrCreate(['key' => 'iconic_series'], ['required_level' => 50]);
        $artist = IconicArtist::factory()->create(['price' => 0]);

        $this->actingAs(User::factory()->create(['xp' => 0]))     // level 1
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->assertCreated();
    }

    public function test_the_room_payload_carries_the_iconic_artist(): void
    {
        $artist = IconicArtist::factory()->create(['name' => 'ABBA']);
        $user = User::factory()->create();

        $code = $this->actingAs($user)
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->json('code');

        $this->actingAs($user)->getJson("/api/rooms/{$code}")
            ->assertOk()
            ->assertJsonPath('iconic_artist_id', $artist->id)
            ->assertJsonPath('iconic_artist.name', 'ABBA');
    }

    public function test_the_trimmed_lobby_patch_keeps_genre_and_artist_pinned(): void
    {
        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $user = User::factory()->create();

        $code = $this->actingAs($user)
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->json('code');

        // The IconicArtistLobbySettings "Party" payload.
        $this->actingAs($user)->patchJson("/api/rooms/{$code}", [
            'songs_per_tier' => 8,
            'enabled_tiers' => ['easy'],
            'guess_timeout_seconds' => 12,
            'mode' => 'custom',
            'player_mode' => 'multiplayer',
            'genre' => 'artist',
            'year_from' => null,
            'year_to' => null,
            'artist_name' => 'Queen',
            'artist_names' => null,
            'dataset_id' => null,
        ])->assertOk();

        $room = GameRoom::where('code', $code)->firstOrFail();
        $this->assertSame('artist', $room->genre->value);
        $this->assertSame('Queen', $room->artist_name);
        $this->assertSame(['easy'], $room->enabled_tiers);
        $this->assertSame('custom', $room->mode->value);
        $this->assertSame('multiplayer', $room->player_mode->value);
        $this->assertSame(8, $room->songs_per_tier);
        $this->assertSame(12, $room->guess_timeout_seconds);
        $this->assertSame($artist->id, $room->iconic_artist_id);   // untouched by update()
    }

    public function test_battle_royale_in_the_trimmed_lobby_respects_its_unlock_level(): void
    {
        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $user = User::factory()->create(['xp' => 0]);   // level 1, below BR's level 3

        $code = $this->actingAs($user)
            ->postJson("/api/iconic-artists/{$artist->id}/start")
            ->json('code');

        $this->actingAs($user)->patchJson("/api/rooms/{$code}", [
            'songs_per_tier' => 5,
            'enabled_tiers' => ['easy'],
            'guess_timeout_seconds' => 8,
            'mode' => 'battle_royale',
            'player_mode' => 'multiplayer',
            'genre' => 'artist',
            'year_from' => null,
            'year_to' => null,
            'artist_name' => 'Queen',
            'artist_names' => null,
            'dataset_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('mode');

        $this->assertSame('custom', GameRoom::where('code', $code)->firstOrFail()->mode->value);
    }
}
