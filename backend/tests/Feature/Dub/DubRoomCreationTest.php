<?php

namespace Tests\Feature\Dub;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DubRoomCreationTest extends TestCase
{
    use CreatesDubRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    public function test_creating_a_dub_room_seats_the_host_and_creates_a_lobby_game(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/dub-rooms', [
            'line_timer_seconds' => 0,
        ]);

        $response->assertCreated();

        $room = GameRoom::where('host_id', $host->id)->firstOrFail();
        $this->assertSame('dub', $room->game);
        $this->assertNotNull($room->dubGame);
        $this->assertSame('lobby', $room->dubGame->state->value);

        // Host is seated (unlike DDF's Game Master) with a dub player state.
        $this->assertSame(1, $room->players()->count());
        $this->assertNotNull($room->players()->first()->dubState);
        $response->assertJsonStructure(['host_player' => ['id', 'nickname', 'connection_token']]);
    }

    public function test_show_returns_the_catch_up_payload(): void
    {
        $room = $this->createDubRoom();
        $this->seatHost($room);

        $response = $this->getJson("/api/dub-rooms/{$room->code}");

        $response->assertOk()->assertJsonStructure([
            'code', 'host_id', 'state', 'round_number', 'total_score',
            'clip', 'role_assignments', 'current_line_index', 'takes',
            'assembled_video_url', 'assembly_error', 'rating' => ['mine', 'count', 'total'],
            'players', 'server_time',
        ]);
        $response->assertJsonPath('state', 'lobby');
    }

    public function test_show_404s_for_a_non_dub_room(): void
    {
        $songleRoom = GameRoom::factory()->create(['game' => 'guess_the_song']);

        $this->getJson("/api/dub-rooms/{$songleRoom->code}")->assertNotFound();
    }
}
