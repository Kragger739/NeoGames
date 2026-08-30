<?php

namespace Tests\Feature\Ddf;

use App\Jobs\AdvanceDdfGameState;
use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DdfVotingTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Queue::fake([AdvanceDdfGameState::class]);
    }

    public function test_a_player_cannot_vote_for_themselves(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete']);
        $p1 = $this->addActivePlayer($room);
        $this->addActivePlayer($room);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());

        $this->expectException(ValidationException::class);
        $service->castVote($room->fresh(), $p1, $p1);
    }

    public function test_an_eliminated_player_cannot_vote(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete']);
        $p1 = $this->addActivePlayer($room, ['is_eliminated' => true]);
        $p2 = $this->addActivePlayer($room);
        $this->addActivePlayer($room);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());

        $this->expectException(ValidationException::class);
        $service->castVote($room->fresh(), $p1, $p2);
    }

    public function test_a_player_cannot_vote_twice_in_the_same_voting_round(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete']);
        $p1 = $this->addActivePlayer($room);
        $p2 = $this->addActivePlayer($room);
        $this->addActivePlayer($room);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());
        $service->castVote($room->fresh(), $p1, $p2);

        $this->expectException(ValidationException::class);
        $service->castVote($room->fresh(), $p1, $p2);
    }

    public function test_all_voted_auto_reveals_and_a_clear_majority_resolves_directly(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete']);
        $p1 = $this->addActivePlayer($room);
        $p2 = $this->addActivePlayer($room);
        $p3 = $this->addActivePlayer($room);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());

        $service->castVote($room->fresh(), $p1, $p3);
        $service->castVote($room->fresh(), $p2, $p3);
        $service->castVote($room->fresh(), $p3, $p1);

        $this->assertSame('round_complete', $room->fresh()->ddfGame->state->value);
        $this->assertSame(2, $p3->fresh()->ddfState->hearts);
    }

    public function test_a_tie_triggers_an_automatic_revote_restricted_to_the_tied_candidates(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete']);
        $p1 = $this->addActivePlayer($room);
        $p2 = $this->addActivePlayer($room);
        $p3 = $this->addActivePlayer($room);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());

        // 3-way tie: p1->p2, p2->p3, p3->p1
        $service->castVote($room->fresh(), $p1, $p2);
        $service->castVote($room->fresh(), $p2, $p3);
        $service->castVote($room->fresh(), $p3, $p1);

        $game = $room->fresh()->ddfGame;
        $this->assertSame('voting', $game->state->value);
        $this->assertTrue($game->is_revote);
        $this->assertCount(3, $game->tie_candidate_player_ids);

        // Voting for someone outside the tied set is rejected during a revote.
        $outsider = $this->addActivePlayer($room);
        $this->expectException(ValidationException::class);
        $service->castVote($room->fresh(), $p1, $outsider);
    }

    public function test_resolve_tie_rejects_a_target_outside_the_tied_candidates(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete']);
        $p1 = $this->addActivePlayer($room);
        $p2 = $this->addActivePlayer($room);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());
        $service->castVote($room->fresh(), $p1, $p2);
        $service->castVote($room->fresh(), $p2, $p1);
        // 2-player revote also ties 1-1 - now blocked pending GM resolution.
        $service->castVote($room->fresh(), $p1, $p2);
        $service->castVote($room->fresh(), $p2, $p1);

        $this->assertSame('voting_results', $room->fresh()->ddfGame->state->value);

        $outsider = $this->addActivePlayer($room);
        $this->expectException(ValidationException::class);
        $service->resolveTie($room->fresh(), $outsider);
    }
}
