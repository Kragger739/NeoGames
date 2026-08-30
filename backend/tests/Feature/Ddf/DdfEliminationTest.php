<?php

namespace Tests\Feature\Ddf;

use App\Events\Ddf\DdfGameOver;
use App\Events\Ddf\DdfPlayerEliminated;
use App\Jobs\AdvanceDdfGameState;
use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DdfEliminationTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Queue::fake([AdvanceDdfGameState::class]);
    }

    public function test_hearts_reaching_zero_eliminates_the_player_and_broadcasts(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete']);
        $p1 = $this->addActivePlayer($room, ['hearts' => 1]);
        $p2 = $this->addActivePlayer($room);
        $p3 = $this->addActivePlayer($room);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());
        $service->castVote($room->fresh(), $p1, $p2);
        $service->castVote($room->fresh(), $p2, $p1);
        $service->castVote($room->fresh(), $p3, $p1);

        $this->assertSame(0, $p1->fresh()->ddfState->hearts);
        $this->assertTrue($p1->fresh()->ddfState->is_eliminated);
        Event::assertDispatched(DdfPlayerEliminated::class, fn ($e) => $e->player->id === $p1->id && $e->reason === 'hearts_zero');
    }

    /**
     * With exactly 2 active players voting for each other, the result is
     * always a 1-1 tie - never a clear majority - so this exercises the
     * tie -> automatic revote -> still-tied -> GM-resolves path (the same
     * one DdfVotingTest's stuck-tie case covers) as the route to a win.
     */
    public function test_the_win_condition_fires_game_over_with_the_correct_survivor(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete']);
        $p1 = $this->addActivePlayer($room, ['hearts' => 1]);
        $p2 = $this->addActivePlayer($room);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());
        $service->castVote($room->fresh(), $p1, $p2);
        $service->castVote($room->fresh(), $p2, $p1);
        // Revote (auto-triggered by the first tie) also ties 1-1 - blocks on the GM.
        $service->castVote($room->fresh(), $p1, $p2);
        $service->castVote($room->fresh(), $p2, $p1);
        $service->resolveTie($room->fresh(), $p1);

        $this->assertSame('game_over', $room->fresh()->ddfGame->state->value);
        $this->assertSame($p2->id, $room->fresh()->ddfGame->winner_room_player_id);
        Event::assertDispatched(DdfGameOver::class, fn ($e) => $e->game->winner_room_player_id === $p2->id);
    }

    public function test_the_gm_can_manually_eliminate_a_player_independent_of_hearts(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete']);
        $p1 = $this->addActivePlayer($room, ['hearts' => 3]);
        $p2 = $this->addActivePlayer($room);

        app(DdfGameService::class)->eliminatePlayer($room, $p1);

        $this->assertTrue($p1->fresh()->ddfState->is_eliminated);
        $this->assertSame(3, $p1->fresh()->ddfState->hearts);
        $this->assertSame('game_over', $room->fresh()->ddfGame->state->value);
        $this->assertSame($p2->id, $room->fresh()->ddfGame->winner_room_player_id);
    }
}
