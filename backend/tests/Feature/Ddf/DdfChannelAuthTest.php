<?php

namespace Tests\Feature\Ddf;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DdfChannelAuthTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    private function authorize(string $channelName, array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    public function test_the_rooms_host_can_authorize_the_gm_channel(): void
    {
        $room = $this->createDdfRoom();

        $response = $this->actingAs($room->host)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-room.{$room->code}.gm",
        ]);

        $response->assertOk();
    }

    public function test_a_different_host_cannot_authorize_the_gm_channel(): void
    {
        $room = $this->createDdfRoom();
        $otherHost = User::factory()->create();

        $response = $this->actingAs($otherHost)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-room.{$room->code}.gm",
        ]);

        $response->assertForbidden();
    }

    /**
     * The one dedicated regression this new channel needs - a valid
     * X-Player-Token must never resolve here even though the "player"
     * guard is checked first for the underlying room, exactly like the
     * public room.{code} channel already guards against the reverse case.
     */
    public function test_a_valid_room_player_token_cannot_authorize_the_gm_channel(): void
    {
        $room = $this->createDdfRoom();
        $player = $this->addActivePlayer($room);

        $response = $this->authorize("private-room.{$room->code}.gm", [
            'X-Player-Token' => $player->connection_token,
        ]);

        $response->assertForbidden();
    }
}
