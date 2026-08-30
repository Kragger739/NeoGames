<?php

namespace Tests\Feature\Room;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BattleRoyaleRequiresLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_low_level_host_cannot_create_a_battle_royale_room(): void
    {
        $host = User::factory()->create(['xp' => 0]);

        $response = $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'battle_royale']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('mode');
    }

    public function test_a_high_enough_level_host_can_create_a_battle_royale_room(): void
    {
        // xp 300 = level 3, the default config('leveling.battle_royale_min_level').
        $host = User::factory()->create(['xp' => 300]);

        $response = $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'battle_royale']);

        $response->assertCreated();
        $response->assertJsonPath('mode', 'battle_royale');
    }

    public function test_a_low_level_host_cannot_switch_an_existing_room_to_battle_royale(): void
    {
        $host = User::factory()->create(['xp' => 0]);
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'classic']);

        $response = $this->actingAs($host)->patchJson("/api/rooms/{$room->code}", ['mode' => 'battle_royale']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('mode');
        $this->assertSame('classic', $room->fresh()->mode->value);
    }

    /**
     * The gate must never fire for any mode other than Battle Royale -
     * even a level-0 account can create/keep a Classic or Solo room.
     */
    public function test_the_level_gate_never_blocks_non_battle_royale_modes(): void
    {
        $host = User::factory()->create(['xp' => 0]);

        $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'classic'])->assertCreated();
        $this->actingAs($host)->postJson('/api/rooms', ['mode' => 'custom'])->assertCreated();
    }
}
