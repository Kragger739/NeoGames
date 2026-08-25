<?php

namespace Tests\Feature\Room;

use App\Models\GameRoom;
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
            'songs_per_tier' => 5,
            'guess_timeout_seconds' => 10,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('songs_per_tier', 5);
        $response->assertJsonPath('guess_timeout_seconds', 10);
        $response->assertJsonPath('status', 'lobby');
        $response->assertJsonPath('current_tier', 'easy');
        $response->assertJsonPath('mode', 'classic');
        $response->assertJsonPath('genre', 'normal');
        $response->assertJsonPath('year_from', null);
        $response->assertJsonPath('year_to', null);

        $this->assertDatabaseHas('game_rooms', ['host_id' => $host->id]);
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

    public function test_viewing_an_unknown_room_code_404s(): void
    {
        $this->getJson('/api/rooms/NOPE99')->assertNotFound();
    }

    public function test_a_room_can_be_created_with_a_specific_mode(): void
    {
        $host = User::factory()->create();

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

        $response = $this->actingAs($host)->postJson('/api/rooms', ['genre' => 'pop']);

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
            'genre' => 'artist',
            'artist_name' => 'Real Artist',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('genre', 'artist');
        $response->assertJsonPath('artist_name', 'Real Artist');
    }
}
