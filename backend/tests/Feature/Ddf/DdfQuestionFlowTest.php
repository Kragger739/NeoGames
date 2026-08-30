<?php

namespace Tests\Feature\Ddf;

use App\Jobs\AdvanceDdfGameState;
use App\Models\DdfAnswer;
use App\Models\GameRoom;
use App\Models\User;
use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DdfQuestionFlowTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Queue::fake([AdvanceDdfGameState::class]);
    }

    public function test_starting_a_question_creates_one_answer_for_the_turn_player(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start']);
        $p1 = $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        app(DdfGameService::class)->startNextQuestion($room->fresh());

        $game = $room->fresh()->ddfGame;
        $answers = DdfAnswer::where('game_room_id', $room->id)->where('question_number', 1)->get();

        $this->assertCount(1, $answers);
        $this->assertSame($p1->id, $answers->first()->room_player_id);
        $this->assertSame($p1->id, $game->current_turn_room_player_id);
        $this->assertNull($answers->first()->submitted_at);
    }

    public function test_turn_order_advances_in_join_order_and_wraps_around(): void
    {
        // High rounds_per_voting so the third nextQuestion() still loops a
        // question rather than auto-starting voting at the cap.
        $room = $this->createDdfRoom(['state' => 'game_start', 'rounds_per_voting' => 10]);
        $p1 = $this->addActivePlayer($room);
        $p2 = $this->addActivePlayer($room);
        $this->seedQuestions(10);

        $service = app(DdfGameService::class);

        $service->startNextQuestion($room->fresh());
        $this->assertSame($p1->id, $room->fresh()->ddfGame->current_turn_room_player_id);

        $room->fresh()->ddfGame->update(['state' => 'question_result']);
        $service->nextQuestion($room->fresh());
        $this->assertSame($p2->id, $room->fresh()->ddfGame->current_turn_room_player_id);

        $room->fresh()->ddfGame->update(['state' => 'question_result']);
        $service->nextQuestion($room->fresh());
        $this->assertSame($p1->id, $room->fresh()->ddfGame->current_turn_room_player_id);
    }

    public function test_a_submission_from_the_turn_player_immediately_locks_the_answer(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'couch_mode' => false]);
        $p1 = $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        $service = app(DdfGameService::class);
        $service->startNextQuestion($room->fresh());

        $service->submitAnswer($room->fresh(), $p1, 'answer');

        $this->assertSame('answer_submitted', $room->fresh()->ddfGame->state->value);
    }

    public function test_a_non_turn_players_submission_is_rejected(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start']);
        $this->addActivePlayer($room);
        $p2 = $this->addActivePlayer($room);
        $this->seedQuestions();

        $service = app(DdfGameService::class);
        $service->startNextQuestion($room->fresh());

        $this->expectException(ValidationException::class);
        $service->submitAnswer($room->fresh(), $p2, 'answer');
    }

    public function test_marking_the_turn_players_answer_reveals_the_question_result(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'couch_mode' => false]);
        $p1 = $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        $service = app(DdfGameService::class);
        $service->startNextQuestion($room->fresh());
        $service->submitAnswer($room->fresh(), $p1, 'answer');

        $answer = DdfAnswer::where('game_room_id', $room->id)->where('question_number', 1)->first();

        $service->markAnswer($room->fresh(), $answer, true);

        $this->assertSame('question_result', $room->fresh()->ddfGame->state->value);
    }

    public function test_couch_mode_allows_marking_directly_from_question_state(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'couch_mode' => true]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        $service = app(DdfGameService::class);
        $service->startNextQuestion($room->fresh());

        $this->assertSame('question', $room->fresh()->ddfGame->state->value);

        $answer = DdfAnswer::where('game_room_id', $room->id)->where('question_number', 1)->first();

        $service->markAnswer($room->fresh(), $answer, true);

        $this->assertSame('question_result', $room->fresh()->ddfGame->state->value);
    }

    /** Resolve the current question (skip) and advance - either to the next question or into voting. */
    private function playQuestion(GameRoom $room): void
    {
        $service = app(DdfGameService::class);
        $service->skipQuestion($room->fresh());
        $service->nextQuestion($room->fresh());
    }

    public function test_voting_auto_starts_once_every_active_player_has_had_their_turns(): void
    {
        // 2 players, "questions before voting" = 2  =>  4 questions (p1,p2,p1,p2) then voting.
        $room = $this->createDdfRoom(['state' => 'game_start', 'rounds_per_voting' => 2]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions(10);

        $service = app(DdfGameService::class);
        $service->startNextQuestion($room->fresh());

        $this->playQuestion($room); // q1 -> q2
        $this->assertSame('question', $room->fresh()->ddfGame->state->value);
        $this->playQuestion($room); // q2 -> q3
        $this->assertSame('question', $room->fresh()->ddfGame->state->value);
        $this->playQuestion($room); // q3 -> q4
        $this->assertSame('question', $room->fresh()->ddfGame->state->value);

        $service->skipQuestion($room->fresh()); // q4 -> question_result
        $versionBefore = $room->fresh()->ddfGame->state_version;
        $service->nextQuestion($room->fresh()); // everyone has had 2 turns -> voting

        $game = $room->fresh()->ddfGame;
        $this->assertSame('voting', $game->state->value);
        $this->assertFalse($game->is_revote);
        $this->assertSame(1, $game->voting_round_number);
        $this->assertSame($versionBefore + 1, $game->state_version);
        Queue::assertPushed(AdvanceDdfGameState::class);
    }

    public function test_a_third_player_who_has_not_had_their_turns_keeps_the_cycle_going(): void
    {
        // 3 players, 2 questions each  =>  6 questions before voting.
        $room = $this->createDdfRoom(['state' => 'game_start', 'rounds_per_voting' => 2]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions(20);

        $service = app(DdfGameService::class);
        $service->startNextQuestion($room->fresh());

        for ($i = 1; $i <= 5; $i++) {
            $this->playQuestion($room);
            $this->assertSame('question', $room->fresh()->ddfGame->state->value, "after question {$i}");
        }

        $this->playQuestion($room); // 6th question resolved -> everyone has had 2 turns
        $this->assertSame('voting', $room->fresh()->ddfGame->state->value);
    }

    public function test_couch_mode_still_auto_starts_voting_with_the_turn_based_rule(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'rounds_per_voting' => 1, 'couch_mode' => true]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions(10);

        $service = app(DdfGameService::class);
        $service->startNextQuestion($room->fresh());

        $this->playQuestion($room); // q1 -> q2 (p2 hasn't had a turn yet)
        $this->assertSame('question', $room->fresh()->ddfGame->state->value);
        $this->playQuestion($room); // q2 resolved -> both have had 1 turn -> voting

        $this->assertSame('voting', $room->fresh()->ddfGame->state->value);
        Queue::assertPushed(AdvanceDdfGameState::class);
    }

    public function test_lowering_questions_before_voting_mid_cycle_auto_starts_voting_on_the_next_advance(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'rounds_per_voting' => 5]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions(10);

        $service = app(DdfGameService::class);
        $service->startNextQuestion($room->fresh());

        $this->playQuestion($room);              // q1 -> q2
        $service->skipQuestion($room->fresh());  // q2 -> question_result; each player now has 1 answer this cycle

        $service->updateSettings($room->fresh(), ['rounds_per_voting' => 1]);
        $service->nextQuestion($room->fresh());  // everyone has had 1 turn >= 1 -> voting

        $this->assertSame('voting', $room->fresh()->ddfGame->state->value);
    }

    public function test_next_question_from_round_complete_starts_a_fresh_cycle_and_stamps_the_cycle_marker(): void
    {
        $room = $this->createDdfRoom([
            'state' => 'round_complete', 'rounds_played_this_cycle' => 0, 'current_question_number' => 4,
        ]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        app(DdfGameService::class)->nextQuestion($room->fresh());

        $game = $room->fresh()->ddfGame;
        $this->assertSame('question', $game->state->value);
        $this->assertSame(5, $game->current_question_number);
        $this->assertSame(5, $game->cycle_started_question_number);
    }

    public function test_creating_a_ddf_room_without_rounds_per_voting_defaults_to_two(): void
    {
        $host = User::factory()->create();

        $this->actingAs($host)->postJson('/api/ddf-rooms')->assertCreated();

        $room = GameRoom::where('host_id', $host->id)->firstOrFail();
        $this->assertSame(2, $room->ddfGame->rounds_per_voting);
    }

    public function test_present_exposes_this_cycles_answer_history_per_player(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'rounds_per_voting' => 5, 'couch_mode' => false]);
        $p1 = $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions(10);

        $service = app(DdfGameService::class);

        // q1: p1's turn, answered correct.
        $service->startNextQuestion($room->fresh());
        $service->submitAnswer($room->fresh(), $p1, 'answer');
        $a1 = DdfAnswer::where('game_room_id', $room->id)->where('question_number', 1)->first();
        $service->markAnswer($room->fresh(), $a1, true);
        $service->nextQuestion($room->fresh());

        // q2: p2's turn - skip it (p1 gets no row).
        $room->fresh()->ddfGame->update(['state' => 'answer_submitted']);
        $service->skipQuestion($room->fresh());
        $service->nextQuestion($room->fresh());

        // q3: p1's turn again, timed out ungraded.
        $room->fresh()->ddfGame->update(['state' => 'answer_submitted']);
        $service->skipQuestion($room->fresh());

        $body = $this->getJson("/api/ddf-rooms/{$room->code}")->assertOk()->json();
        $history = $body['cycle_answers'][(string) $p1->id];

        $this->assertCount(2, $history); // q1 and q3, not q2 (p2's turn)
        $this->assertSame(1, $history[0]['question_number']);
        $this->assertTrue($history[0]['is_correct']);
        $this->assertSame(3, $history[1]['question_number']);
        $this->assertNull($history[1]['is_correct']);
        $this->assertNotSame('', $history[0]['question_text']);
    }

    public function test_the_game_only_ever_picks_questions_in_its_own_language(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start', 'language' => 'de']);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions(3, 'en');
        $this->seedQuestions(2, 'de');

        $service = app(DdfGameService::class);

        // Exhausts both German questions (startNextQuestion()'s used-
        // question exclusion is based on an answer row existing for this
        // room, not submission status - each call already creates one) -
        // if English ones ever leaked in, a third distinct pick would
        // succeed instead of falling back to a repeat.
        $service->startNextQuestion($room->fresh());
        $first = $room->fresh()->ddfGame->currentQuestion;
        $this->assertSame('de', $first->language->value);

        $service->startNextQuestion($room->fresh());
        $second = $room->fresh()->ddfGame->currentQuestion;
        $this->assertSame('de', $second->language->value);
        $this->assertNotSame($first->id, $second->id);

        // Both German questions used - the repeat-allowed fallback still
        // must not reach into the English pool.
        $service->startNextQuestion($room->fresh());
        $third = $room->fresh()->ddfGame->currentQuestion;
        $this->assertSame('de', $third->language->value);
    }
}
