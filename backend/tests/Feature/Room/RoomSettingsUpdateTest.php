<?php

namespace Tests\Feature\Room;

use App\Events\RoomSettingsUpdated;
use App\Models\GameRoom;
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
            'mode' => 'solo',
        ]);

        $response->assertOk();
        $response->assertJsonPath('songs_per_tier', 5);
        $response->assertJsonPath('guess_timeout_seconds', 15);
        $response->assertJsonPath('mode', 'solo');

        $room->refresh();
        $this->assertSame(5, $room->songs_per_tier);
        $this->assertSame(15, $room->guess_timeout_seconds);
        $this->assertSame('solo', $room->mode->value);

        Event::assertDispatched(RoomSettingsUpdated::class);
    }

    public function test_a_non_host_cannot_update_someone_elses_room_settings(): void
    {
        $host = User::factory()->create();
        $otherHost = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($otherHost)->patchJson("/api/rooms/{$room->code}", [
            'mode' => 'solo',
        ]);

        $response->assertForbidden();
        $this->assertSame('classic', $room->fresh()->mode->value);
    }

    public function test_settings_cannot_be_changed_once_the_room_is_active(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['status' => 'active']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'mode' => 'solo',
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
        $room = GameRoom::factory()->for($host, 'host')->create(['genre' => 'normal']);

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
        $room = GameRoom::factory()->for($host, 'host')->create(['genre' => 'normal']);

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
        $room = GameRoom::factory()->for($host, 'host')->create(['genre' => 'normal']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", [
            'genre' => 'normal',
            'year_from' => null,
            'year_to' => null,
            'songs_per_tier' => 6,
        ]);

        $response->assertOk();
        $response->assertJsonPath('songs_per_tier', 6);
    }
}
