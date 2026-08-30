<?php

namespace Tests\Feature\Room;

use App\Enums\SongGenre;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_can_create_a_room_with_a_unique_code(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            // Classic mode forces its own fixed defaults (see the
            // dedicated test below) - custom here so this test's actual
            // subject, custom settings sticking, stays meaningful.
            'mode' => 'custom',
            'songs_per_tier' => 5,
            'guess_timeout_seconds' => 10,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('songs_per_tier', 5);
        $response->assertJsonPath('guess_timeout_seconds', 10);
        $response->assertJsonPath('status', 'lobby');
        $response->assertJsonPath('current_tier', 'easy');
        $response->assertJsonPath('mode', 'custom');
        $response->assertJsonPath('genre', 'normal');
        $response->assertJsonPath('year_from', null);
        $response->assertJsonPath('year_to', null);

        $this->assertDatabaseHas('game_rooms', ['host_id' => $host->id]);
    }

    /**
     * Classic has no configurable settings - it always plays with these
     * fixed, "as intended" defaults, ignoring anything else a request
     * tries to submit for them. Covers both the implicit default (mode
     * omitted) and an explicit 'classic', since store() resolves both the
     * same way.
     */
    public function test_a_classic_mode_room_always_gets_the_fixed_iconic_defaults(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            'songs_per_tier' => 5,
            'guess_timeout_seconds' => 20,
            'genre' => 'pop',
            'enabled_tiers' => ['easy', 'hard'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('mode', 'classic');
        $response->assertJsonPath('genre', 'iconic');
        $response->assertJsonPath('songs_per_tier', 1);
        $response->assertJsonPath('guess_timeout_seconds', 8);
        $response->assertJsonPath('enabled_tiers', ['easy', 'intermediate', 'medium', 'hard', 'extreme']);
        $response->assertJsonPath('year_from', null);
        $response->assertJsonPath('year_to', null);
        $response->assertJsonPath('artist_name', null);
        $response->assertJsonPath('artist_names', null);
    }

    /**
     * Regression: a non-Classic room must never end up with the
     * Classic-exclusive "iconic" genre, even if a request explicitly
     * submits it - Iconic is only ever valid while mode is Classic.
     */
    public function test_a_non_classic_room_cannot_be_created_with_the_iconic_genre(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            'mode' => 'custom',
            'genre' => 'iconic',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('mode', 'custom');
        $response->assertJsonPath('genre', 'normal');
    }

    public function test_the_host_is_automatically_seated_as_a_player(): void
    {
        $host = User::factory()->create(['name' => 'Alice Host', 'username' => 'alicehost']);

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            'songs_per_tier' => 5,
            'guess_timeout_seconds' => 10,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('player.nickname', 'alicehost');
        $this->assertNotEmpty($response->json('player.connection_token'));
        $this->assertNotEmpty($response->json('player.id'));
        $response->assertJsonPath('players.0.nickname', 'alicehost');
        $response->assertJsonPath('players.0.score', 0);

        $room = GameRoom::where('host_id', $host->id)->firstOrFail();
        $this->assertSame(1, $room->players()->count());
        $this->assertSame($host->id, $room->players()->first()->user_id);
    }

    public function test_room_creation_requires_authentication(): void
    {
        $this->postJson('/api/rooms')->assertUnauthorized();
    }

    public function test_room_codes_are_unique(): void
    {
        $host = User::factory()->create();
        GameRoom::factory()->for($host, 'host')->create(['code' => 'AAAAAA']);

        $response = $this->actingAs($host)->postJson('/api/rooms');

        $response->assertCreated();
        $this->assertNotSame('AAAAAA', $response->json('code'));
    }

    public function test_anyone_can_view_a_room_by_code(): void
    {
        $room = GameRoom::factory()->create(['code' => 'ABC123']);

        $response = $this->getJson('/api/rooms/abc123');

        $response->assertOk();
        $response->assertJsonPath('code', 'ABC123');
    }

    /**
     * A linked player's level resolves from their account's xp (via
     * RoomPlayer::level() + scopeSelectForSummary()'s eager load); an
     * anonymous, nickname-only seat has no account to resolve one from.
     */
    public function test_room_view_includes_each_players_level_where_they_have_an_account(): void
    {
        $leveledUser = User::factory()->create(['xp' => 300]);
        $room = GameRoom::factory()->create();
        $room->players()->create([
            'user_id' => $leveledUser->id,
            'nickname' => 'leveled',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);
        $room->players()->create([
            'user_id' => null,
            'nickname' => 'anon',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $response = $this->getJson("/api/rooms/{$room->code}");

        $response->assertOk();
        $players = collect($response->json('players'))->keyBy('nickname');
        $this->assertSame(3, $players['leveled']['level']);
        $this->assertNull($players['anon']['level']);
    }

    /**
     * host_id lets the frontend tell "a host is logged in" apart from "the
     * host logged in right now owns this room" - without it, any logged-in
     * host visiting any lobby (not just their own) would see the
     * settings-editor/Start-game UI meant only for the room's actual owner
     * (confirmed live - a second host account could see a friend's lobby
     * settings before this field existed).
     */
    public function test_room_view_includes_the_owning_hosts_id(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['code' => 'ABC123']);

        $response = $this->getJson('/api/rooms/abc123');

        $response->assertOk();
        $response->assertJsonPath('host_id', $host->id);
    }

    public function test_viewing_an_unknown_room_code_404s(): void
    {
        $this->getJson('/api/rooms/NOPE99')->assertNotFound();
    }

    public function test_a_room_can_be_created_with_a_specific_mode(): void
    {
        // Battle Royale is level-gated (see BattleRoyaleRequiresLevelTest) -
        // a fresh level-1 account would be rejected here for a reason
        // unrelated to what this test actually covers (mode selection).
        $host = User::factory()->create(['xp' => 1000]);

        $response = $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'battle_royale']);

        $response->assertCreated();
        $response->assertJsonPath('mode', 'battle_royale');
    }

    public function test_room_creation_rejects_an_unknown_mode(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'nonsense']);

        $response->assertUnprocessable();
    }

    public function test_a_room_can_be_created_with_a_specific_genre(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'custom', 'genre' => 'pop']);

        $response->assertCreated();
        $response->assertJsonPath('genre', 'pop');
    }

    public function test_room_creation_rejects_an_unknown_genre(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', ['genre' => 'nonsense']);

        $response->assertUnprocessable();
    }

    public function test_room_creation_with_year_genre_requires_a_year_range(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', ['genre' => 'year']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['year_from', 'year_to']);
    }

    public function test_room_creation_rejects_year_from_after_year_to(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            'genre' => 'year',
            'year_from' => 1990,
            'year_to' => 1980,
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_room_can_be_created_with_a_valid_year_range(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            'mode' => 'custom',
            'genre' => 'year',
            'year_from' => 1970,
            'year_to' => 1989,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('genre', 'year');
        $response->assertJsonPath('year_from', 1970);
        $response->assertJsonPath('year_to', 1989);
    }

    public function test_room_creation_with_artist_genre_requires_a_name(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', ['genre' => 'artist']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['artist_name']);
    }

    public function test_a_room_can_be_created_with_an_artist_name(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            'mode' => 'custom',
            'genre' => 'artist',
            'artist_name' => 'Real Artist',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('genre', 'artist');
        $response->assertJsonPath('artist_name', 'Real Artist');
    }

    public function test_room_creation_with_multi_artist_genre_requires_names(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', ['genre' => 'multi_artist']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['artist_names']);
    }

    public function test_room_creation_rejects_more_artists_than_the_safety_max(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            'genre' => 'multi_artist',
            'artist_names' => array_map(fn (int $i) => "Artist {$i}", range(1, SongGenre::MAX_MULTI_ARTIST_COUNT + 1)),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['artist_names']);
    }

    public function test_a_room_can_be_created_with_multi_artist_genre_and_names(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            'mode' => 'custom',
            'genre' => 'multi_artist',
            'artist_names' => ['Real Artist', 'Another Artist'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('genre', 'multi_artist');
        $response->assertJsonPath('artist_names', ['Real Artist', 'Another Artist']);
        // artist_name (singular) stays untouched/null - a separate column
        // owned by the single-Artist genre, not repurposed by MultiArtist.
        $response->assertJsonPath('artist_name', null);
    }

    public function test_room_creation_requires_at_least_one_enabled_tier(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', ['enabled_tiers' => []]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['enabled_tiers']);
    }

    public function test_room_creation_rejects_an_unknown_tier_value(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', ['enabled_tiers' => ['bogus']]);

        $response->assertUnprocessable();
    }

    public function test_a_room_can_be_created_with_a_subset_of_enabled_tiers(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms', [
            'mode' => 'custom',
            'enabled_tiers' => ['hard', 'easy'],
        ]);

        $response->assertCreated();
        // Canonical Easy->Extreme order, not the request's submission order.
        $response->assertJsonPath('enabled_tiers', ['easy', 'hard']);
    }

    public function test_room_creation_defaults_to_all_five_tiers_when_not_specified(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/rooms');

        $response->assertCreated();
        $response->assertJsonPath('enabled_tiers', ['easy', 'intermediate', 'medium', 'hard', 'extreme']);
    }

    /**
     * roundNumber()/totalRounds() are pure attribute reads (no query
     * needed - current_tier/current_song_index/songs_per_tier are already
     * on the in-memory model), so these construct a GameRoom directly
     * rather than round-tripping through the database.
     */
    public function test_round_number_accounts_for_completed_tiers_and_the_current_index(): void
    {
        $room = new GameRoom([
            'current_tier' => 'easy',
            'current_song_index' => 0,
            'songs_per_tier' => 3,
        ]);
        $this->assertSame(1, $room->roundNumber());

        $room = new GameRoom([
            'current_tier' => 'easy',
            'current_song_index' => 2,
            'songs_per_tier' => 3,
        ]);
        $this->assertSame(3, $room->roundNumber());

        // Second tier (Intermediate), first round - a full Easy tier
        // (3 rounds) already completed.
        $room = new GameRoom([
            'current_tier' => 'intermediate',
            'current_song_index' => 0,
            'songs_per_tier' => 3,
        ]);
        $this->assertSame(4, $room->roundNumber());

        // Last tier (Extreme), last round - the final round overall.
        $room = new GameRoom([
            'current_tier' => 'extreme',
            'current_song_index' => 2,
            'songs_per_tier' => 3,
        ]);
        $this->assertSame(15, $room->roundNumber());
        $this->assertSame(15, $room->totalRounds());
    }

    public function test_total_rounds_is_songs_per_tier_times_every_difficulty_tier(): void
    {
        $room = new GameRoom(['songs_per_tier' => 4]);

        $this->assertSame(20, $room->totalRounds());
    }

    public function test_enabled_tiers_falls_back_to_all_five_when_null_or_empty(): void
    {
        $room = new GameRoom(['enabled_tiers' => null]);
        $this->assertSame(['easy', 'intermediate', 'medium', 'hard', 'extreme'], array_map(fn ($t) => $t->value, $room->enabledTiers()));

        $room = new GameRoom(['enabled_tiers' => []]);
        $this->assertSame(['easy', 'intermediate', 'medium', 'hard', 'extreme'], array_map(fn ($t) => $t->value, $room->enabledTiers()));
    }

    public function test_enabled_tiers_preserves_canonical_order_regardless_of_storage_order(): void
    {
        $room = new GameRoom(['enabled_tiers' => ['extreme', 'easy']]);

        $this->assertSame(['easy', 'extreme'], array_map(fn ($t) => $t->value, $room->enabledTiers()));
    }

    public function test_first_and_next_enabled_tier_walk_the_rooms_own_subset(): void
    {
        $room = new GameRoom(['enabled_tiers' => ['hard', 'easy'], 'current_tier' => 'easy']);

        $this->assertSame('easy', $room->firstEnabledTier()->value);
        $this->assertSame('hard', $room->nextEnabledTier()->value);

        $room = new GameRoom(['enabled_tiers' => ['hard', 'easy'], 'current_tier' => 'hard']);
        $this->assertNull($room->nextEnabledTier());
    }

    /**
     * With Intermediate and Hard disabled, round math must walk the room's
     * own [Easy, Medium, Extreme] subset, not the fixed 5-tier list.
     */
    public function test_round_number_and_total_rounds_respect_a_partial_enabled_tier_subset(): void
    {
        $room = new GameRoom([
            'enabled_tiers' => ['easy', 'medium', 'extreme'],
            'current_tier' => 'medium',
            'current_song_index' => 1,
            'songs_per_tier' => 3,
        ]);

        // 1 full tier (Easy, 3 rounds) already completed + 2nd round of Medium.
        $this->assertSame(5, $room->roundNumber());
        $this->assertSame(9, $room->totalRounds());
    }
}
