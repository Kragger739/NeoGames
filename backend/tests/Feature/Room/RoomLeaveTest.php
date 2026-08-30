<?php

namespace Tests\Feature\Room;

use App\Models\GameRoom;
use App\Models\Guess;
use App\Models\RoomPlayer;
use App\Models\Round;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomLeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_leaving_with_others_still_seated_only_removes_their_own_seat(): void
    {
        $room = GameRoom::factory()->create();
        $leaving = RoomPlayer::factory()->for($room, 'room')->create();
        $staying = RoomPlayer::factory()->for($room, 'room')->create();

        $response = $this->withHeader('X-Player-Token', $leaving->connection_token)
            ->deleteJson("/api/rooms/{$room->code}/leave");

        $response->assertNoContent();
        $this->assertModelMissing($leaving);
        $this->assertModelExists($staying);
        $this->assertModelExists($room);
    }

    public function test_the_last_player_leaving_deletes_the_room(): void
    {
        $room = GameRoom::factory()->create();
        $player = RoomPlayer::factory()->for($room, 'room')->create();

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->deleteJson("/api/rooms/{$room->code}/leave");

        $response->assertNoContent();
        $this->assertModelMissing($room);
    }

    /**
     * Deleting the room can't just leave orphaned rounds/guesses behind -
     * both get cleaned up as part of the same deletion.
     */
    public function test_the_last_player_leaving_deletes_the_rounds_and_guesses_too(): void
    {
        $room = GameRoom::factory()->create();
        $player = RoomPlayer::factory()->for($room, 'room')->create();
        $round = Round::factory()->for($room, 'room')->create();
        $guess = Guess::factory()->for($round, 'round')->for($player, 'player')->create();

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->deleteJson("/api/rooms/{$room->code}/leave")
            ->assertNoContent();

        $this->assertModelMissing($round);
        $this->assertModelMissing($guess);
    }

    public function test_a_host_can_leave_their_own_room_as_a_player(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create();
        RoomPlayer::factory()->for($room, 'room')->create(['user_id' => $host->id]);

        $response = $this->actingAs($host)->deleteJson("/api/rooms/{$room->code}/leave");

        $response->assertNoContent();
        $this->assertModelMissing($room);
    }

    public function test_leaving_a_room_youre_not_seated_in_is_a_quiet_no_op(): void
    {
        $room = GameRoom::factory()->create();
        $staying = RoomPlayer::factory()->for($room, 'room')->create();
        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)->deleteJson("/api/rooms/{$room->code}/leave");

        $response->assertNoContent();
        $this->assertModelExists($room);
        $this->assertModelExists($staying);
    }

    public function test_leaving_requires_authentication(): void
    {
        $room = GameRoom::factory()->create();
        RoomPlayer::factory()->for($room, 'room')->create();

        $this->deleteJson("/api/rooms/{$room->code}/leave")->assertUnauthorized();
    }
}
