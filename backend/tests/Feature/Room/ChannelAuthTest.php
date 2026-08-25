<?php

namespace Tests\Feature\Room;

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private function authorize(string $channelName, array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    public function test_a_host_can_authorize_their_own_room_channel(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();

        $response = $this->actingAs($host)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-room.{$room->code}",
        ]);

        $response->assertOk();
    }

    public function test_a_host_cannot_authorize_another_hosts_room_channel(): void
    {
        $host = User::factory()->create();
        $otherHost = User::factory()->create();
        $room = GameRoom::factory()->for($otherHost, 'host')->create();

        $response = $this->actingAs($host)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "presence-room.{$room->code}",
        ]);

        $response->assertForbidden();
    }

    public function test_a_player_can_authorize_their_own_rooms_channel(): void
    {
        $room = GameRoom::factory()->create();
        $player = RoomPlayer::factory()->for($room, 'room')->create();

        $response = $this->authorize("presence-room.{$room->code}", [
            'X-Player-Token' => $player->connection_token,
        ]);

        $response->assertOk();
    }

    public function test_a_player_cannot_authorize_a_different_rooms_channel(): void
    {
        $room = GameRoom::factory()->create();
        $otherRoom = GameRoom::factory()->create();
        $player = RoomPlayer::factory()->for($room, 'room')->create();

        $response = $this->authorize("presence-room.{$otherRoom->code}", [
            'X-Player-Token' => $player->connection_token,
        ]);

        $response->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $room = GameRoom::factory()->create();

        $response = $this->authorize("presence-room.{$room->code}");

        $response->assertUnauthorized();
    }

    public function test_an_invalid_player_token_is_rejected(): void
    {
        $room = GameRoom::factory()->create();

        $response = $this->authorize("presence-room.{$room->code}", [
            'X-Player-Token' => 'not-a-real-token',
        ]);

        $response->assertUnauthorized();
    }
}
