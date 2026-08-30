<?php

namespace Tests\Feature\Ddf;

use App\Jobs\AdvanceDdfGameState;
use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DdfQuestionTimerTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([AdvanceDdfGameState::class]);
        Event::fake();
    }

    public function test_a_stale_timer_call_is_a_no_op(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'state_version' => 5]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        app(DdfGameService::class)->handleTimerExpired($room->ddfGame->id, 1);

        $this->assertSame('game_start', $room->fresh()->ddfGame->state->value);
    }

    public function test_a_live_call_transitions_gamestart_to_question_and_dispatches_the_next_timer(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'state_version' => 1, 'couch_mode' => false]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        app(DdfGameService::class)->handleTimerExpired($room->ddfGame->id, 1);

        $game = $room->fresh()->ddfGame;
        $this->assertSame('question', $game->state->value);

        Queue::assertPushed(
            AdvanceDdfGameState::class,
            fn ($job) => $job->ddfGameId === $game->id && $job->expectedStateVersion === $game->state_version,
        );
    }

    public function test_a_paused_game_ignores_a_timer_fire(): void
    {
        $room = $this->createDdfRoom(['state' => 'question', 'state_version' => 1, 'is_paused' => true]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        app(DdfGameService::class)->handleTimerExpired($room->ddfGame->id, 1);

        $this->assertSame('question', $room->fresh()->ddfGame->state->value);
    }

    public function test_couch_mode_skips_the_question_timer_dispatch(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'couch_mode' => true]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        app(DdfGameService::class)->startNextQuestion($room->fresh());

        Queue::assertNotPushed(AdvanceDdfGameState::class);
    }

    public function test_couch_mode_still_dispatches_the_voting_timer(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete', 'couch_mode' => true]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);

        app(DdfGameService::class)->startVoting($room->fresh());

        Queue::assertPushed(AdvanceDdfGameState::class);
    }
}
