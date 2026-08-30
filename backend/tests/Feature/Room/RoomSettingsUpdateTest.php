<?php

namespace Tests\Feature\Room;

use App\Events\RoomSettingsUpdated;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RoomSettingsUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_host_can_update_settings_while_the_room_is_in_the_lobby(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create([
            'songs_per_tier' => 3,
            'guess_timeout_seconds' => 8,
            'mode' => 'classic',
        ]);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'songs_per_tier' => 5,
            'guess_timeout_seconds' => 15,
            'mode' => 'custom',
        ]);

        $response->assertOk();
        $response->assertJsonPath('songs_per_tier', 5);
        $response->assertJsonPath('guess_timeout_seconds', 15);
        $response->assertJsonPath('mode', 'custom');

        $room->refresh();
        $this->assertSame(5, $room->songs_per_tier);
        $this->assertSame(15, $room->guess_timeout_seconds);
        $this->assertSame('custom', $room->mode->value);

        Event::assertDispatched(RoomSettingsUpdated::class);
    }

    public function test_a_non_host_cannot_update_someone_elses_room_settings(): void
    {
        $host = User::factory()->create();
        $otherHost = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($otherHost)->patchJson("/api/rooms/{$room->code}", [
            'mode' => 'custom',
        ]);

        $response->assertForbidden();
        $this->assertSame('classic', $room->fresh()->mode->value);
    }

    public function test_settings_cannot_be_changed_once_the_room_is_active(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['status' => 'active']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'mode' => 'custom',
        ]);

        $response->assertUnprocessable();
        $this->assertSame('classic', $room->fresh()->mode->value);
    }

    public function test_update_rejects_an_unknown_mode(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'mode' => 'nonsense',
        ]);

        $response->assertUnprocessable();
    }

    public function test_the_host_can_switch_to_year_genre_with_a_valid_range(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        // Classic mode forces its own fixed defaults (see the dedicated
        // tests for that) - custom here so this test's actual subject, a
        // genre change sticking, stays meaningful.
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'custom', 'genre' => 'normal']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'year',
            'year_from' => 1970,
            'year_to' => 1989,
        ]);

        $response->assertOk();
        $response->assertJsonPath('genre', 'year');
        $response->assertJsonPath('year_from', 1970);
        $response->assertJsonPath('year_to', 1989);

        $room->refresh();
        $this->assertSame('year', $room->genre->value);
        $this->assertSame(1970, $room->year_from);
        $this->assertSame(1989, $room->year_to);
    }

    public function test_update_rejects_an_unknown_genre(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'nonsense',
        ]);

        $response->assertUnprocessable();
    }

    public function test_switching_to_year_genre_without_a_range_is_rejected(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'year',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['year_from', 'year_to']);
    }

    public function test_year_from_after_year_to_is_rejected(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'year',
            'year_from' => 1990,
            'year_to' => 1980,
        ]);

        $response->assertUnprocessable();
    }

    public function test_switching_away_from_year_genre_clears_the_stored_range(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'custom',
            'genre' => 'year',
            'year_from' => 1970,
            'year_to' => 1989,
        ]);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'pop',
        ]);

        $response->assertOk();
        $response->assertJsonPath('year_from', null);
        $response->assertJsonPath('year_to', null);

        $room->refresh();
        $this->assertNull($room->year_from);
        $this->assertNull($room->year_to);
    }

    public function test_updating_other_settings_while_in_year_mode_leaves_the_range_untouched(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'custom',
            'genre' => 'year',
            'year_from' => 1970,
            'year_to' => 1989,
        ]);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'songs_per_tier' => 6,
        ]);

        $response->assertOk();
        $response->assertJsonPath('genre', 'year');
        $response->assertJsonPath('year_from', 1970);
        $response->assertJsonPath('year_to', 1989);
    }

    public function test_the_host_can_switch_to_artist_genre_with_a_name(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'custom', 'genre' => 'normal']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'artist',
            'artist_name' => 'Real Artist',
        ]);

        $response->assertOk();
        $response->assertJsonPath('genre', 'artist');
        $response->assertJsonPath('artist_name', 'Real Artist');

        $room->refresh();
        $this->assertSame('artist', $room->genre->value);
        $this->assertSame('Real Artist', $room->artist_name);
    }

    public function test_switching_to_artist_genre_without_a_name_is_rejected(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'artist',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['artist_name']);
    }

    public function test_switching_away_from_artist_genre_clears_the_stored_name(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'custom',
            'genre' => 'artist',
            'artist_name' => 'Real Artist',
        ]);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'pop',
        ]);

        $response->assertOk();
        $response->assertJsonPath('artist_name', null);

        $room->refresh();
        $this->assertNull($room->artist_name);
    }

    public function test_switching_from_artist_to_year_genre_clears_the_stored_name_and_sets_the_range(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'custom',
            'genre' => 'artist',
            'artist_name' => 'Real Artist',
        ]);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'year',
            'year_from' => 1970,
            'year_to' => 1989,
        ]);

        $response->assertOk();
        $response->assertJsonPath('artist_name', null);
        $response->assertJsonPath('year_from', 1970);
        $response->assertJsonPath('year_to', 1989);

        $room->refresh();
        $this->assertNull($room->artist_name);
    }

    public function test_the_host_can_switch_to_multi_artist_genre_with_names(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'custom', 'genre' => 'normal']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'multi_artist',
            'artist_names' => ['Real Artist', 'Another Artist'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('genre', 'multi_artist');
        $response->assertJsonPath('artist_names', ['Real Artist', 'Another Artist']);

        $room->refresh();
        $this->assertSame('multi_artist', $room->genre->value);
        $this->assertSame(['Real Artist', 'Another Artist'], $room->artist_names);
    }

    public function test_switching_to_multi_artist_genre_without_names_is_rejected(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'multi_artist',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['artist_names']);
    }

    public function test_switching_away_from_multi_artist_genre_clears_the_stored_names(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'custom',
            'genre' => 'multi_artist',
            'artist_names' => ['Real Artist', 'Another Artist'],
        ]);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'pop',
        ]);

        $response->assertOk();
        $response->assertJsonPath('artist_names', null);

        $room->refresh();
        $this->assertNull($room->artist_names);
    }

    public function test_the_host_can_narrow_the_enabled_tiers(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'custom']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'enabled_tiers' => ['easy', 'extreme'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('enabled_tiers', ['easy', 'extreme']);

        $room->refresh();
        $this->assertSame(['easy', 'extreme'], $room->enabled_tiers);
    }

    public function test_clearing_every_enabled_tier_is_rejected(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'enabled_tiers' => [],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['enabled_tiers']);
    }

    /**
     * Regression test: the frontend always sends explicit year_from/
     * year_to keys (null, not omitted, when genre isn't "year") alongside
     * whatever field is actually being changed - without `nullable` on
     * those rules, this literal null used to fail the `integer` check even
     * though genre isn't "year" here at all.
     */
    public function test_updating_an_unrelated_setting_with_explicit_null_years_is_accepted_when_genre_is_not_year(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'custom', 'genre' => 'normal']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'normal',
            'year_from' => null,
            'year_to' => null,
            'songs_per_tier' => 6,
        ]);

        $response->assertOk();
        $response->assertJsonPath('songs_per_tier', 6);
    }

    /**
     * Classic has no configurable settings - a PATCH attempting to change
     * genre/tiers/timeout on an already-Classic room is silently
     * overridden back to the fixed defaults rather than rejected, since a
     * compliant client never sends them while in Classic mode in the
     * first place (see RoomSettingsForm.tsx).
     */
    public function test_updating_settings_on_a_classic_mode_room_is_forced_back_to_the_fixed_defaults(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'classic']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'pop',
            'songs_per_tier' => 7,
            'guess_timeout_seconds' => 30,
            'enabled_tiers' => ['easy'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('genre', 'iconic');
        $response->assertJsonPath('songs_per_tier', 1);
        $response->assertJsonPath('guess_timeout_seconds', 8);
        $response->assertJsonPath('enabled_tiers', ['easy', 'intermediate', 'medium', 'hard', 'extreme']);

        $room->refresh();
        $this->assertSame('iconic', $room->genre->value);
        $this->assertSame(1, $room->songs_per_tier);
        $this->assertSame(8, $room->guess_timeout_seconds);
    }

    /**
     * player_mode is fully orthogonal to mode - Classic's settings-locking
     * logic must never touch it.
     */
    public function test_a_classic_mode_room_can_independently_switch_player_mode(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'classic', 'player_mode' => 'multiplayer']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'player_mode' => 'solo',
        ]);

        $response->assertOk();
        $response->assertJsonPath('mode', 'classic');
        $response->assertJsonPath('player_mode', 'solo');
    }

    public function test_switching_to_solo_player_mode_is_rejected_with_other_players_seated(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['player_mode' => 'multiplayer']);
        RoomPlayer::factory()->for($room, 'room')->create();
        RoomPlayer::factory()->for($room, 'room')->create();

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'player_mode' => 'solo',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_mode');
        $this->assertSame('multiplayer', $room->fresh()->player_mode->value);
    }

    public function test_switching_to_solo_player_mode_succeeds_with_only_the_host_seated(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['player_mode' => 'multiplayer']);
        RoomPlayer::factory()->for($room, 'room')->create(['user_id' => $host->id]);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'player_mode' => 'solo',
        ]);

        $response->assertOk();
        $response->assertJsonPath('player_mode', 'solo');
    }

    /**
     * Switching mode TO classic in the same request the settings are
     * submitted forces the fixed defaults too - not just a PATCH on a room
     * already in Classic.
     */
    public function test_switching_to_classic_mode_forces_the_fixed_defaults_in_the_same_request(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'custom', 'genre' => 'artist', 'artist_name' => 'Real Artist']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'mode' => 'classic',
            'genre' => 'pop',
        ]);

        $response->assertOk();
        $response->assertJsonPath('mode', 'classic');
        $response->assertJsonPath('genre', 'iconic');
        $response->assertJsonPath('artist_name', null);
    }

    /**
     * Regression: switching a room OFF Classic must never carry the
     * Classic-exclusive "iconic" genre along, even when the request
     * explicitly submits it (a stale value from the frontend's own
     * pre-switch state - see RoomSettingsForm.tsx's Mode onChange - or any
     * other client) - Iconic is only ever valid while mode is Classic.
     */
    public function test_switching_away_from_classic_mode_normalizes_a_stale_iconic_genre(): void
    {
        Event::fake([RoomSettingsUpdated::class]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'classic', 'genre' => 'iconic']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'mode' => 'custom',
            'genre' => 'iconic',
        ]);

        $response->assertOk();
        $response->assertJsonPath('mode', 'custom');
        $response->assertJsonPath('genre', 'normal');
    }
}
