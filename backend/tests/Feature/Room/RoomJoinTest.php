<?php

namespace Tests\Feature\Room;

use App\Enums\RoomStatus;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_can_join_a_lobby_room_and_receives_a_token(): void
    {
        $room = GameRoom::factory()->create(['code' => 'JOINME']);

        $response = $this->postJson('/api/rooms/joinme/join', ['nickname' => 'Alice']);

        $response->assertCreated();
        $response->assertJsonPath('nickname', 'Alice');
        $response->assertJsonStructure(['id', 'nickname', 'connection_token', 'room_code']);

        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'nickname' => 'Alice',
        ]);
    }

    public function test_duplicate_nicknames_in_the_same_room_are_rejected(): void
    {
        $room = GameRoom::factory()->create();
        RoomPlayer::factory()->for($room, 'room')->create(['nickname' => 'Alice']);

        $response = $this->postJson("/api/rooms/{$room->code}/join", ['nickname' => 'alice']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('nickname');
    }

    public function test_cannot_join_a_room_that_has_already_started(): void
    {
        $room = GameRoom::factory()->create(['status' => RoomStatus::Active->value]);

        $response = $this->postJson("/api/rooms/{$room->code}/join", ['nickname' => 'Alice']);

        $response->assertUnprocessable();
    }

    public function test_nickname_is_required(): void
    {
        $room = GameRoom::factory()->create();

        $response = $this->postJson("/api/rooms/{$room->code}/join", ['nickname' => '']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('nickname');
    }

    public function test_a_logged_in_visitor_auto_joins_as_themself_with_no_nickname(): void
    {
        $room = GameRoom::factory()->create();
        $user = User::factory()->create(['username' => 'bobby']);

        $response = $this->actingAs($user)->postJson("/api/rooms/{$room->code}/join");

        $response->assertCreated();
        $response->assertJsonPath('nickname', 'bobby');

        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'nickname' => 'bobby',
        ]);
    }

    public function test_a_logged_in_visitors_nickname_is_auto_suffixed_on_collision(): void
    {
        $room = GameRoom::factory()->create();
        RoomPlayer::factory()->for($room, 'room')->create(['nickname' => 'bobby']);
        $user = User::factory()->create(['username' => 'bobby']);

        $response = $this->actingAs($user)->postJson("/api/rooms/{$room->code}/join");

        $response->assertCreated();
        $this->assertNotSame('bobby', $response->json('nickname'));
        $this->assertStringStartsWith('bobby', $response->json('nickname'));
    }

    public function test_reopening_a_join_link_while_already_seated_returns_the_same_seat(): void
    {
        $room = GameRoom::factory()->create();
        $user = User::factory()->create(['username' => 'bobby']);

        $first = $this->actingAs($user)->postJson("/api/rooms/{$room->code}/join");
        $first->assertCreated();

        $second = $this->actingAs($user)->postJson("/api/rooms/{$room->code}/join");
        $second->assertOk();
        $second->assertJsonPath('connection_token', $first->json('connection_token'));
        $second->assertJsonPath('id', $first->json('id'));

        $this->assertSame(1, $room->players()->where('user_id', $user->id)->count());
    }

    public function test_a_logged_in_visitor_can_reopen_their_seat_even_after_the_room_started(): void
    {
        $room = GameRoom::factory()->create();
        $user = User::factory()->create(['username' => 'bobby']);

        $joined = $this->actingAs($user)->postJson("/api/rooms/{$room->code}/join");
        $joined->assertCreated();

        $room->update(['status' => RoomStatus::Active->value]);

        $reopened = $this->actingAs($user)->postJson("/api/rooms/{$room->code}/join");
        $reopened->assertOk();
        $reopened->assertJsonPath('connection_token', $joined->json('connection_token'));
    }

    public function test_anonymous_join_still_leaves_user_id_null(): void
    {
        $room = GameRoom::factory()->create();

        $this->postJson("/api/rooms/{$room->code}/join", ['nickname' => 'Anon'])->assertCreated();

        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'nickname' => 'Anon',
            'user_id' => null,
        ]);
    }

    /**
     * The host's own auto-seat from room creation already counts as the
     * room's one occupant - a genuinely new player is rejected outright,
     * not just capped after the fact.
     */
    public function test_a_second_player_cannot_join_a_solo_room(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['player_mode' => 'solo']);
        RoomPlayer::factory()->for($room, 'room')->create(['user_id' => $host->id]);

        $response = $this->postJson("/api/rooms/{$room->code}/join", ['nickname' => 'Intruder']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('nickname');
        $this->assertSame(1, $room->players()->count());
    }

    /**
     * Reclaiming your own existing seat (see the "reopening a join link"
     * tests above) must keep working in a solo room - the solo cap only
     * ever blocks a genuinely new occupant, never the one player already
     * seated.
     */
    public function test_the_host_can_still_reopen_their_own_seat_in_a_solo_room(): void
    {
        $host = User::factory()->create(['username' => 'soloHost']);
        $room = GameRoom::factory()->for($host, 'host')->create(['player_mode' => 'solo']);
        $seat = RoomPlayer::factory()->for($room, 'room')->create(['user_id' => $host->id, 'nickname' => 'soloHost']);

        $response = $this->actingAs($host)->postJson("/api/rooms/{$room->code}/join");

        $response->assertOk();
        $response->assertJsonPath('id', $seat->id);
    }
}
