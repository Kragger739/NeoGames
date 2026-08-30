<?php

namespace Tests\Feature\Friends;

use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Models\Friendship;
use App\Models\GameRoom;
use App\Models\User;
use App\Notifications\FriendRequestAcceptedNotification;
use App\Notifications\FriendRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FriendshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_can_send_a_friend_request_by_username(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $other = User::factory()->create(['username' => 'buddy']);

        $response = $this->actingAs($host)->postJson('/api/friends', ['username' => 'buddy']);

        $response->assertCreated();
        $this->assertDatabaseHas('friendships', [
            'user_id' => $host->id,
            'friend_id' => $other->id,
            'status' => 'pending',
        ]);
    }

    public function test_sending_a_request_notifies_the_recipient_live(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $other = User::factory()->create(['username' => 'buddy']);

        $this->actingAs($host)->postJson('/api/friends', ['username' => 'buddy'])->assertCreated();

        Notification::assertSentTo(
            $other,
            FriendRequestNotification::class,
            fn (FriendRequestNotification $notification) => $notification->from->is($host),
        );
    }

    public function test_cannot_send_a_friend_request_to_yourself(): void
    {
        $host = User::factory()->create(['username' => 'me']);

        $response = $this->actingAs($host)->postJson('/api/friends', ['username' => 'me']);

        $response->assertUnprocessable();
    }

    public function test_cannot_send_a_duplicate_friend_request(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create(['username' => 'buddy']);
        Friendship::create(['user_id' => $host->id, 'friend_id' => $other->id, 'status' => 'pending']);

        $response = $this->actingAs($host)->postJson('/api/friends', ['username' => 'buddy']);

        $response->assertUnprocessable();
    }

    public function test_the_recipient_can_accept_a_pending_request(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $other = User::factory()->create();
        $friendship = Friendship::create(['user_id' => $host->id, 'friend_id' => $other->id, 'status' => 'pending']);

        $response = $this->actingAs($other)->postJson("/api/friends/{$friendship->id}/accept");

        $response->assertNoContent();
        $this->assertDatabaseHas('friendships', ['id' => $friendship->id, 'status' => 'accepted']);
    }

    public function test_accepting_a_request_notifies_the_original_requester_live(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $other = User::factory()->create();
        $friendship = Friendship::create(['user_id' => $host->id, 'friend_id' => $other->id, 'status' => 'pending']);

        $this->actingAs($other)->postJson("/api/friends/{$friendship->id}/accept")->assertNoContent();

        Notification::assertSentTo(
            $host,
            FriendRequestAcceptedNotification::class,
            fn (FriendRequestAcceptedNotification $notification) => $notification->accepter->is($other),
        );
    }

    public function test_the_requester_cannot_accept_their_own_request(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create();
        $friendship = Friendship::create(['user_id' => $host->id, 'friend_id' => $other->id, 'status' => 'pending']);

        $response = $this->actingAs($host)->postJson("/api/friends/{$friendship->id}/accept");

        $response->assertForbidden();
    }

    public function test_either_party_can_remove_a_friendship(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create();
        $friendship = Friendship::create(['user_id' => $host->id, 'friend_id' => $other->id, 'status' => 'accepted']);

        $response = $this->actingAs($other)->deleteJson("/api/friends/{$friendship->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('friendships', ['id' => $friendship->id]);
    }

    public function test_a_stranger_cannot_remove_someone_elses_friendship(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create();
        $stranger = User::factory()->create();
        $friendship = Friendship::create(['user_id' => $host->id, 'friend_id' => $other->id, 'status' => 'accepted']);

        $response = $this->actingAs($stranger)->deleteJson("/api/friends/{$friendship->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('friendships', ['id' => $friendship->id]);
    }

    public function test_the_index_lists_friends_and_pending_requests_from_both_directions(): void
    {
        $host = User::factory()->create();
        $friend = User::factory()->create(['username' => 'confirmed']);
        $incoming = User::factory()->create(['username' => 'wantsme']);
        $outgoing = User::factory()->create(['username' => 'iwant']);

        Friendship::create(['user_id' => $host->id, 'friend_id' => $friend->id, 'status' => 'accepted']);
        Friendship::create(['user_id' => $incoming->id, 'friend_id' => $host->id, 'status' => 'pending']);
        Friendship::create(['user_id' => $host->id, 'friend_id' => $outgoing->id, 'status' => 'pending']);

        $response = $this->actingAs($host)->getJson('/api/friends');

        $response->assertOk();
        $response->assertJsonCount(1, 'friends');
        $response->assertJsonPath('friends.0.username', 'confirmed');
        $response->assertJsonCount(1, 'incoming_requests');
        $response->assertJsonPath('incoming_requests.0.user.username', 'wantsme');
        $response->assertJsonCount(1, 'outgoing_requests');
        $response->assertJsonPath('outgoing_requests.0.user.username', 'iwant');
    }

    public function test_the_index_includes_each_friends_xp(): void
    {
        $host = User::factory()->create();
        $friend = User::factory()->create(['username' => 'confirmed', 'xp' => 300]);
        Friendship::create(['user_id' => $host->id, 'friend_id' => $friend->id, 'status' => 'accepted']);

        $response = $this->actingAs($host)->getJson('/api/friends');

        $response->assertOk();
        $response->assertJsonPath('friends.0.xp', 300);
    }

    public function test_the_index_includes_a_friends_joinable_room_code(): void
    {
        $host = User::factory()->create();
        $friend = User::factory()->create(['username' => 'confirmed']);
        Friendship::create(['user_id' => $host->id, 'friend_id' => $friend->id, 'status' => 'accepted']);

        $room = GameRoom::factory()->for($friend, 'host')->create([
            'status' => RoomStatus::Lobby->value,
            'player_mode' => RoomPlayerMode::Multiplayer->value,
        ]);

        $response = $this->actingAs($host)->getJson('/api/friends');

        $response->assertOk();
        $response->assertJsonPath('friends.0.current_room_code', $room->code);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unjoinableRoomStates(): array
    {
        return [
            'active room' => [RoomStatus::Active->value, RoomPlayerMode::Multiplayer->value],
            'finished room' => [RoomStatus::Finished->value, RoomPlayerMode::Multiplayer->value],
            'solo room' => [RoomStatus::Lobby->value, RoomPlayerMode::Solo->value],
        ];
    }

    #[DataProvider('unjoinableRoomStates')]
    public function test_the_index_omits_a_friends_room_code_when_not_joinable(string $status, string $playerMode): void
    {
        $host = User::factory()->create();
        $friend = User::factory()->create(['username' => 'confirmed']);
        Friendship::create(['user_id' => $host->id, 'friend_id' => $friend->id, 'status' => 'accepted']);

        GameRoom::factory()->for($friend, 'host')->create([
            'status' => $status,
            'player_mode' => $playerMode,
        ]);

        $response = $this->actingAs($host)->getJson('/api/friends');

        $response->assertOk();
        $response->assertJsonPath('friends.0.current_room_code', null);
    }
}
