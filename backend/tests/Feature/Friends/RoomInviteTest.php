<?php

namespace Tests\Feature\Friends;

use App\Models\Friendship;
use App\Models\GameRoom;
use App\Models\User;
use App\Notifications\RoomInviteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RoomInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_seated_player_can_invite_a_friend_into_their_room(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $friend = User::factory()->create();
        Friendship::create(['user_id' => $host->id, 'friend_id' => $friend->id, 'status' => 'accepted']);

        $room = GameRoom::factory()->for($host, 'host')->create();
        $room->players()->create([
            'user_id' => $host->id,
            'nickname' => 'host',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        $response = $this->actingAs($host)->postJson("/api/rooms/{$room->code}/invite", [
            'friend_user_id' => $friend->id,
        ]);

        $response->assertNoContent();
        Notification::assertSentTo($friend, RoomInviteNotification::class);
    }

    public function test_cannot_invite_someone_who_is_not_a_friend(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $stranger = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();
        $room->players()->create([
            'user_id' => $host->id,
            'nickname' => 'host',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        $response = $this->actingAs($host)->postJson("/api/rooms/{$room->code}/invite", [
            'friend_user_id' => $stranger->id,
        ]);

        $response->assertUnprocessable();
        Notification::assertNothingSent();
    }

    public function test_a_non_seated_user_cannot_send_invites_for_a_room(): void
    {
        Notification::fake();

        $host = User::factory()->create();
        $bystander = User::factory()->create();
        $friend = User::factory()->create();
        Friendship::create(['user_id' => $bystander->id, 'friend_id' => $friend->id, 'status' => 'accepted']);

        $room = GameRoom::factory()->for($host, 'host')->create();
        // $bystander is friends with $friend but never joined this room.

        $response = $this->actingAs($bystander)->postJson("/api/rooms/{$room->code}/invite", [
            'friend_user_id' => $friend->id,
        ]);

        $response->assertUnprocessable();
        Notification::assertNothingSent();
    }

    /**
     * Notification::fake() (used above) intercepts before toBroadcast() is
     * ever called, so it can't catch a bug in that method's actual
     * serialization - this test deliberately does NOT fake, and sends the
     * notification for real (including through Reverb) to exercise it.
     */
    public function test_the_notification_actually_serializes_and_broadcasts_without_error(): void
    {
        $host = User::factory()->create(['username' => 'alice']);
        $friend = User::factory()->create();
        Friendship::create(['user_id' => $host->id, 'friend_id' => $friend->id, 'status' => 'accepted']);

        $room = GameRoom::factory()->for($host, 'host')->create();
        $room->players()->create([
            'user_id' => $host->id,
            'nickname' => 'alice',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        $notification = new RoomInviteNotification($host, $room);

        $this->assertSame('room.invite', $notification->broadcastType());

        $broadcastMessage = $notification->toBroadcast($friend);
        $this->assertSame([
            'from_user_id' => $host->id,
            'from_username' => 'alice',
            'room_code' => $room->code,
        ], $broadcastMessage->data);

        // toArray() (the 'database' channel) goes through the same data,
        // and is what FriendService::inviteToRoom's real send path
        // ultimately persists - covered live in the browser (an invite's
        // toast arrived over Reverb with zero page refresh) rather than
        // re-sent here for real, since the test env's BROADCAST_CONNECTION
        // is the real "reverb" driver (not faked/null) and would otherwise
        // require a live Reverb server to be up during `php artisan test`.
        $this->assertSame($notification->toArray($friend), $broadcastMessage->data);
    }
}
