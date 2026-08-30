<?php

namespace Tests\Feature\Ddf;

use App\Events\Ddf\DdfVotingStarted;
use App\Jobs\AdvanceDdfGameState;
use App\Models\DdfAnswer;
use App\Models\DdfQuestion;
use App\Models\DdfVote;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DdfSafeModeTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Queue::fake([AdvanceDdfGameState::class]);
    }

    /** @param array<int, bool|null> $correctness question_number 1.. => is_correct */
    private function recordCycleAnswers(GameRoom $room, RoomPlayer $player, array $correctness): void
    {
        $question = DdfQuestion::create([
            'category' => 'history', 'language' => 'en',
            'text' => 'q '.uniqid(), 'correct_answer' => 'answer',
        ]);

        foreach ($correctness as $questionNumber => $isCorrect) {
            DdfAnswer::create([
                'game_room_id' => $room->id,
                'ddf_question_id' => $question->id,
                'room_player_id' => $player->id,
                'question_number' => $questionNumber,
                'answer_text' => 'x',
                'submitted_at' => now(),
                'is_correct' => $isCorrect,
                'marked_at' => $isCorrect === null ? null : now(),
            ]);
        }
    }

    public function test_safe_mode_off_leaves_an_all_correct_player_voteable(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete', 'safe_mode' => false, 'cycle_started_question_number' => 1]);
        $p1 = $this->addActivePlayer($room);
        $p2 = $this->addActivePlayer($room);
        $this->recordCycleAnswers($room, $p1, [1 => true, 2 => true]);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());

        Event::assertDispatched(DdfVotingStarted::class, fn (DdfVotingStarted $e) => in_array($p1->id, $e->eligibleTargetIds, true));

        // and a vote against p1 goes through
        $service->castVote($room->fresh(), $p2, $p1);
        $this->assertDatabaseHas('ddf_votes', ['target_room_player_id' => $p1->id]);
    }

    public function test_safe_mode_on_removes_an_all_correct_player_as_a_target(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete', 'safe_mode' => true, 'cycle_started_question_number' => 1]);
        $p1 = $this->addActivePlayer($room);
        $p2 = $this->addActivePlayer($room);
        $p3 = $this->addActivePlayer($room);
        $this->recordCycleAnswers($room, $p1, [1 => true, 2 => true]);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());

        Event::assertDispatched(DdfVotingStarted::class, function (DdfVotingStarted $e) use ($p1, $p2, $p3) {
            return ! in_array($p1->id, $e->eligibleTargetIds, true)
                && in_array($p2->id, $e->eligibleTargetIds, true)
                && in_array($p3->id, $e->eligibleTargetIds, true)
                && in_array($p1->id, $e->eligibleVoterIds, true); // still allowed to vote
        });

        $this->expectException(ValidationException::class);
        $service->castVote($room->fresh(), $p2, $p1);
    }

    public function test_safe_mode_on_a_safe_player_never_loses_a_life_even_with_the_most_votes(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete', 'safe_mode' => true, 'cycle_started_question_number' => 1]);
        $p1 = $this->addActivePlayer($room); // safe
        $p2 = $this->addActivePlayer($room);
        $p3 = $this->addActivePlayer($room);
        $this->recordCycleAnswers($room, $p1, [1 => true, 2 => true]);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());

        // Force raw votes onto p1 (bypassing castVote's guard) then reveal.
        $round = $room->fresh()->ddfGame->voting_round_number;
        foreach ([$p2, $p3] as $voter) {
            DdfVote::create([
                'game_room_id' => $room->id, 'voting_round_number' => $round,
                'voter_room_player_id' => $voter->id, 'target_room_player_id' => $p1->id,
            ]);
        }
        $service->endVoting($room->fresh());

        $this->assertSame(3, $p1->fresh()->ddfState->hearts); // untouched
    }

    public function test_a_null_graded_answer_this_cycle_makes_a_player_not_safe(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete', 'safe_mode' => true, 'cycle_started_question_number' => 1]);
        $p1 = $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->recordCycleAnswers($room, $p1, [1 => true, 2 => null]);

        app(DdfGameService::class)->startVoting($room->fresh());

        Event::assertDispatched(DdfVotingStarted::class, fn (DdfVotingStarted $e) => in_array($p1->id, $e->eligibleTargetIds, true));
    }

    public function test_a_player_asked_nothing_this_cycle_is_not_safe(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete', 'safe_mode' => true, 'cycle_started_question_number' => 5]);
        $p1 = $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        // Answer rows exist but for an earlier cycle (question_number < 5).
        $this->recordCycleAnswers($room, $p1, [1 => true, 2 => true]);

        app(DdfGameService::class)->startVoting($room->fresh());

        Event::assertDispatched(DdfVotingStarted::class, fn (DdfVotingStarted $e) => in_array($p1->id, $e->eligibleTargetIds, true));
    }

    public function test_all_players_safe_falls_back_to_everyone_voteable(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete', 'safe_mode' => true, 'cycle_started_question_number' => 1]);
        $p1 = $this->addActivePlayer($room);
        $p2 = $this->addActivePlayer($room);
        $this->recordCycleAnswers($room, $p1, [1 => true]);
        $this->recordCycleAnswers($room, $p2, [2 => true]);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());

        Event::assertDispatched(DdfVotingStarted::class, function (DdfVotingStarted $e) use ($p1, $p2) {
            return in_array($p1->id, $e->eligibleTargetIds, true) && in_array($p2->id, $e->eligibleTargetIds, true);
        });

        // A vote lands and a loser is chosen.
        $service->castVote($room->fresh(), $p1, $p2);
        $service->castVote($room->fresh(), $p2, $p1);
        $this->assertContains($room->fresh()->ddfGame->state->value, ['round_complete', 'voting', 'voting_results']);
    }

    public function test_revote_after_a_safe_filtered_reveal_never_targets_the_safe_player(): void
    {
        $room = $this->createDdfRoom(['state' => 'round_complete', 'safe_mode' => true, 'cycle_started_question_number' => 1]);
        $p1 = $this->addActivePlayer($room); // safe
        $p2 = $this->addActivePlayer($room);
        $p3 = $this->addActivePlayer($room);
        $p4 = $this->addActivePlayer($room);
        $this->recordCycleAnswers($room, $p1, [1 => true, 2 => true]);

        $service = app(DdfGameService::class);
        $service->startVoting($room->fresh());

        // p2 and p3 tie 2-2 (p1 is safe, so votes only land on p2/p3/p4).
        $service->castVote($room->fresh(), $p1, $p3);
        $service->castVote($room->fresh(), $p2, $p3);
        $service->castVote($room->fresh(), $p3, $p2);
        $service->castVote($room->fresh(), $p4, $p2);

        $game = $room->fresh()->ddfGame;
        $this->assertSame('voting', $game->state->value);
        $this->assertTrue($game->is_revote);
        $this->assertNotContains($p1->id, $game->tie_candidate_player_ids);
        $this->assertEqualsCanonicalizing([$p2->id, $p3->id], $game->tie_candidate_player_ids);
    }

    public function test_safe_mode_can_be_toggled_via_settings_and_is_blocked_during_voting(): void
    {
        $room = $this->createDdfRoom(['state' => 'question_result', 'safe_mode' => false]);
        $service = app(DdfGameService::class);

        $service->updateSettings($room->fresh(), ['safe_mode' => true]);
        $this->assertTrue($room->fresh()->ddfGame->safe_mode);

        $room->fresh()->ddfGame->update(['state' => 'voting']);
        $this->expectException(ValidationException::class);
        $service->updateSettings($room->fresh(), ['safe_mode' => false]);
    }
}
