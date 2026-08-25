<?php

namespace Tests\Feature\Friends;

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresenceChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private function authorize(string $channelName, array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    public function test_a_logged_in_host_can_authorize_the_online_users_channel(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'presence-online-users',
        ]);

        $response->assertOk();
    }

    public function test_a_room_player_is_rejected_from_the_online_users_channel_not_a_type_error(): void
    {
        $room = GameRoom::factory()->create();
        $player = RoomPlayer::factory()->for($room, 'room')->create();

        $response = $this->authorize('presence-online-users', [
            'X-Player-Token' => $player->connection_token,
        ]);

        $response->assertForbidden();
    }

    public function test_a_user_can_authorize_their_own_private_notification_channel(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$host->id}",
        ]);

        $response->assertOk();
    }

    public function test_a_user_cannot_authorize_someone_elses_private_notification_channel(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$other->id}",
        ]);

        $response->assertForbidden();
    }
}
