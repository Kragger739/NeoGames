<?php

namespace Tests\Feature\Ddf;

use App\Jobs\AdvanceDdfGameState;
use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DdfLobbyReadinessTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        // QUEUE_CONNECTION=sync in testing means an unfaked delayed job
        // fires immediately inline rather than staying pending - fake it
        // so start()'s "get ready" timer doesn't auto-advance the game
        // mid-test.
        Queue::fake([AdvanceDdfGameState::class]);
    }

    public function test_start_is_rejected_below_two_players(): void
    {
        $room = $this->createDdfRoom();
        $this->addActivePlayer($room);

        $this->expectException(ValidationException::class);
        app(DdfGameService::class)->start($room);
    }

    public function test_start_is_rejected_when_any_active_player_is_not_camera_ready(): void
    {
        $room = $this->createDdfRoom();
        $this->addActivePlayer($room, ['is_camera_ready' => true]);
        $this->addActivePlayer($room, ['is_camera_ready' => false]);

        $this->expectException(ValidationException::class);
        app(DdfGameService::class)->start($room);
    }

    public function test_start_succeeds_once_two_or_more_players_are_all_ready(): void
    {
        $room = $this->createDdfRoom();
        $this->addActivePlayer($room, ['is_camera_ready' => true]);
        $this->addActivePlayer($room, ['is_camera_ready' => true]);

        app(DdfGameService::class)->start($room->fresh());

        $this->assertSame('game_start', $room->fresh()->ddfGame->state->value);
    }
}
