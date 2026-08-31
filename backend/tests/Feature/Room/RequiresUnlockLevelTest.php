<?php

namespace Tests\Feature\Room;

use App\Models\GameRoom;
use App\Models\UnlockRequirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequiresUnlockLevelTest extends TestCase
{
    use RefreshDatabase;

    private function setRequirement(string $key, int $level): void
    {
        UnlockRequirement::updateOrCreate(['key' => $key], ['required_level' => $level]);
    }

    // --- Battle Royale (ships gated at level 3) ------------------------------

    public function test_a_low_level_host_cannot_create_a_battle_royale_room(): void
    {
        $host = User::factory()->create(['xp' => 0]);

        $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'battle_royale'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');
    }

    public function test_a_high_enough_level_host_can_create_a_battle_royale_room(): void
    {
        // xp 300 = level 3, the shipped mode:battle_royale requirement.
        $host = User::factory()->create(['xp' => 300]);

        $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'battle_royale'])
            ->assertCreated()
            ->assertJsonPath('mode', 'battle_royale');
    }

    public function test_a_low_level_host_cannot_switch_an_existing_room_to_battle_royale(): void
    {
        $host = User::factory()->create(['xp' => 0]);
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'classic']);

        $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", ['mode' => 'battle_royale'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');
        $this->assertSame('classic', $room->fresh()->mode->value);
    }

    // --- Genre gate (admin-configured) ------------------------------------

    public function test_a_configured_genre_gate_blocks_a_low_level_host_on_create(): void
    {
        $this->setRequirement('genre:pop', 5);
        $host = User::factory()->create(['xp' => 0]);

        $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'custom', 'genre' => 'pop'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('genre');
    }

    public function test_a_configured_genre_gate_blocks_a_low_level_host_on_update(): void
    {
        $this->setRequirement('genre:pop', 5);
        $host = User::factory()->create(['xp' => 0]);
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'custom', 'genre' => 'normal']);

        $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", ['genre' => 'pop'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('genre');
    }

    public function test_an_ungated_genre_is_never_blocked(): void
    {
        $host = User::factory()->create(['xp' => 0]);

        $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'custom', 'genre' => 'pop'])
            ->assertCreated()
            ->assertJsonPath('genre', 'pop');
    }

    // --- Game night gate (admin-configured) ------------------------------

    public function test_a_configured_game_night_gate_blocks_room_creation(): void
    {
        $this->setRequirement('game_night', 3);
        $host = User::factory()->create(['xp' => 0]);

        $this->actingAs($host)->postJson('/api/rooms')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room');
    }

    public function test_the_game_night_gate_clears_once_the_host_is_high_enough(): void
    {
        $this->setRequirement('game_night', 3);
        $host = User::factory()->create(['xp' => 300]); // level 3

        $this->actingAs($host)->postJson('/api/rooms')->assertCreated();
    }

    public function test_the_game_night_gate_does_not_block_joining_someone_elses_room(): void
    {
        $this->setRequirement('game_night', 5);
        $owner = User::factory()->create(['xp' => 1000]);
        $create = $this->actingAs($owner)->postJson('/api/rooms')->assertCreated();
        $code = $create->json('code');

        $low = User::factory()->create(['xp' => 0]);
        $this->actingAs($low)->postJson("/api/rooms/{$code}/join", ['nickname' => 'Newbie'])
            ->assertSuccessful();
    }
}
