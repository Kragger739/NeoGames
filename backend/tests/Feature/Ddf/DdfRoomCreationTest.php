<?php

namespace Tests\Feature\Ddf;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DdfRoomCreationTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    public function test_creating_a_ddf_room_seats_no_room_player_for_the_host(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/ddf-rooms', [
            'rounds_per_voting' => 3,
        ]);

        $response->assertCreated();

        $room = GameRoom::where('host_id', $host->id)->firstOrFail();
        $this->assertSame(0, $room->players()->count());
        $this->assertNotNull($room->ddfGame);
        $this->assertSame('lobby', $room->ddfGame->state->value);
        $this->assertSame(3, $room->ddfGame->rounds_per_voting);
    }

    public function test_the_host_is_rejected_when_hitting_the_generic_join_route_on_their_own_room(): void
    {
        $room = $this->createDdfRoom();

        $response = $this->actingAs($room->host)->postJson("/api/rooms/{$room->code}/join", [
            'nickname' => 'ShouldNotMatter',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, $room->players()->count());
    }
}
