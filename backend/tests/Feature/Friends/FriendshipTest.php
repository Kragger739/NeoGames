<?php

namespace Tests\Feature\Friends;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_can_send_a_friend_request_by_username(): void
    {
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
        $host = User::factory()->create();
        $other = User::factory()->create();
        $friendship = Friendship::create(['user_id' => $host->id, 'friend_id' => $other->id, 'status' => 'pending']);

        $response = $this->actingAs($other)->postJson("/api/friends/{$friendship->id}/accept");

        $response->assertNoContent();
        $this->assertDatabaseHas('friendships', ['id' => $friendship->id, 'status' => 'accepted']);
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
}
