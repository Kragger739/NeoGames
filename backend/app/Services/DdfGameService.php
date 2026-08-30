<?php

namespace App\Services;

use App\Enums\DdfGameState;
use App\Enums\DdfTieResolution;
use App\Events\Ddf\DdfAnswerMarked;
use App\Events\Ddf\DdfAnswersLocked;
use App\Events\Ddf\DdfAnswerSubmittedToGm;
use App\Events\Ddf\DdfCorrectAnswerToGm;
use App\Events\Ddf\DdfGameOver;
use App\Events\Ddf\DdfGamePaused;
use App\Events\Ddf\DdfGameReset;
use App\Events\Ddf\DdfGameResumed;
use App\Events\Ddf\DdfGameStarted;
use App\Events\Ddf\DdfLifeLost;
use App\Events\Ddf\DdfPlayerAnswered;
use App\Events\Ddf\DdfPlayerEliminated;
use App\Events\Ddf\DdfQuestionResult;
use App\Events\Ddf\DdfQuestionStarted;
use App\Events\Ddf\DdfRoundComplete;
use App\Events\Ddf\DdfSettingsUpdated;
use App\Events\Ddf\DdfTieNeedsResolution;
use App\Events\Ddf\DdfVoteCastToGm;
use App\Events\Ddf\DdfVotingProgress;
use App\Events\Ddf\DdfVotingResults;
use App\Events\Ddf\DdfVotingStarted;
use App\Jobs\AdvanceDdfGameState;
use App\Models\DdfAnswer;
use App\Models\DdfGame;
use App\Models\DdfQuestion;
use App\Models\DdfVote;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The sole writer of ddf_games/ddf_player_states/ddf_answers/ddf_votes
 * state transitions - called from DdfGameController/DdfAnswerController/
 * DdfVoteController (GM/player actions) and from AdvanceDdfGameState (the
 * one delayed timer job). Every transition that opens a timed phase bumps
 * state_version before dispatching a new timer job; every transition that
 * closes one early also bumps it (without dispatching) so a still-pending
 * job's later fire becomes a safe no-op - see handleTimerExpired().
 */
class DdfGameService
{
    /** "Get ready" beat between GameStart and the first Question. */
    private const GAME_START_DELAY_SECONDS = 3;

    // ------------------------------------------------------------------
    // Lobby -> GameStart -> Question
    // ------------------------------------------------------------------

    public function start(GameRoom $room): void
    {
        $game = $room->ddfGame;

        if ($game->state !== DdfGameState::Lobby) {
            throw ValidationException::withMessages([
                'room' => ['This game has already started.'],
            ]);
        }

        $active = $this->activePlayers($room);

        if ($active->count() < 2) {
            throw ValidationException::withMessages([
                'room' => ['At least 2 players are needed to start.'],
            ]);
        }

        $notReady = $active->first(fn (RoomPlayer $p) => ! $p->ddfState->is_camera_ready);

        if ($notReady) {
            throw ValidationException::withMessages([
                'room' => ["Every player needs a working camera before you can start ({$notReady->nickname} isn't ready)."],
            ]);
        }

        $game->update([
            'state' => DdfGameState::GameStart->value,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => now(),
        ]);

        broadcast(new DdfGameStarted($game->fresh()));

        $this->dispatchTimer($game->fresh(), self::GAME_START_DELAY_SECONDS);
    }

    public function startNextQuestion(GameRoom $room): void
    {
        $game = $room->ddfGame;

        // A custom dataset scopes the pool to its own questions (it carries
        // its own language); the built-in pool is every row with no dataset,
        // filtered by the game's language - identical to the previous
        // behaviour bar the added whereNull, a no-op for existing rows.
        $pool = fn () => $game->dataset_id !== null
            ? DdfQuestion::where('dataset_id', $game->dataset_id)
            : DdfQuestion::whereNull('dataset_id')->where('language', $game->language->value);

        $question = $pool()
            ->whereNotIn('id', function ($query) use ($room) {
                $query->select('ddf_question_id')->from('ddf_answers')->where('game_room_id', $room->id);
            })->inRandomOrder()->first();

        // Every question in the pool has been used this game - allow repeats
        // rather than getting stuck (a long game can outlast the pool).
        if (! $question) {
            $question = $pool()->inRandomOrder()->first();
        }

        $turnPlayer = $this->nextTurnPlayer($room, $game->current_turn_room_player_id);

        $attrs = [
            'state' => DdfGameState::Question->value,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => now(),
            'current_question_id' => $question->id,
            'current_question_number' => $game->current_question_number + 1,
            'current_turn_room_player_id' => $turnPlayer->id,
        ];

        // rounds_played_this_cycle is 0 exactly at a cycle's first question:
        // at game start, and right after applyHeartLoss() reset it. Mid-cycle
        // nextQuestion() has already bumped it to >=1. Stamp the cycle's
        // starting question number here so "this cycle's answers" (the camera
        // dots, and safe-mode eligibility) have a clean lower bound.
        if ($game->rounds_played_this_cycle === 0) {
            $attrs['cycle_started_question_number'] = $game->current_question_number + 1;
        }

        $game->update($attrs);

        DdfAnswer::create([
            'game_room_id' => $room->id,
            'ddf_question_id' => $question->id,
            'room_player_id' => $turnPlayer->id,
            'question_number' => $game->current_question_number,
        ]);

        $game = $game->fresh();
        broadcast(new DdfQuestionStarted($game));
        // GM-only, on the private channel - carries the correct answer so the
        // GM can grade without waiting for the public reveal.
        broadcast(new DdfCorrectAnswerToGm($game));

        if (! $game->couch_mode) {
            $this->dispatchTimer($game, $game->question_timer_seconds);
        }
    }

    /** GM action: force a fresh random question while the current one has zero submissions yet. */
    public function rerollQuestion(GameRoom $room): void
    {
        $game = $room->ddfGame;

        if ($game->state !== DdfGameState::Question) {
            throw ValidationException::withMessages(['room' => ['No question is currently active.']]);
        }

        $hasSubmissions = DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', $game->current_question_number)
            ->whereNotNull('submitted_at')
            ->exists();

        if ($hasSubmissions) {
            throw ValidationException::withMessages(['room' => ['Someone has already answered - reroll is only available before the first answer.']]);
        }

        DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', $game->current_question_number)
            ->delete();

        $game->update(['current_question_number' => $game->current_question_number - 1]);
        $this->startNextQuestion($room);
    }

    // ------------------------------------------------------------------
    // Question -> AnswerSubmitted -> QuestionResult
    // ------------------------------------------------------------------

    public function submitAnswer(GameRoom $room, RoomPlayer $player, string $answerText): void
    {
        $game = $room->ddfGame;

        if ($game->state !== DdfGameState::Question) {
            throw ValidationException::withMessages(['answer' => ['This question is no longer accepting answers.']]);
        }

        if ($player->id !== $game->current_turn_room_player_id) {
            throw ValidationException::withMessages(['answer' => ["It's not your turn."]]);
        }

        $answer = DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', $game->current_question_number)
            ->where('room_player_id', $player->id)
            ->first();

        if (! $answer) {
            throw ValidationException::withMessages(['answer' => ["You're not an active player in this round."]]);
        }

        if ($answer->submitted_at !== null) {
            throw ValidationException::withMessages(['answer' => ['You already submitted an answer.']]);
        }

        $answer->update(['answer_text' => $answerText, 'submitted_at' => now()]);

        broadcast(new DdfAnswerSubmittedToGm($answer->fresh()->load('player')));
        broadcast(new DdfPlayerAnswered($room, $player, true));

        $this->lockAnswers($room);
    }

    /** Timer expiry or last submission - closes the answer window. */
    public function lockAnswers(GameRoom $room): void
    {
        $game = $room->ddfGame;

        if ($game->state !== DdfGameState::Question) {
            return; // already locked by the other trigger racing this one
        }

        $questionId = $game->current_question_id;

        $game->update([
            'state' => DdfGameState::AnswerSubmitted->value,
            'state_version' => $game->state_version + 1,
        ]);

        broadcast(new DdfAnswersLocked($room, $questionId));

        // Nothing left to mark (everyone timed out with no submission at
        // all) - reveal immediately instead of waiting on a GM who has
        // nothing to grade.
        $unmarkedCount = DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', $game->current_question_number)
            ->whereNull('is_correct')
            ->whereNotNull('submitted_at')
            ->count();

        if ($unmarkedCount === 0) {
            $this->revealQuestionResult($room, skipped: false);
        }
    }

    public function markAnswer(GameRoom $room, DdfAnswer $answer, bool $isCorrect): void
    {
        $game = $room->ddfGame;

        if ($game->state === DdfGameState::Question && $game->couch_mode) {
            // Couch Mode: no typed submission to stamp this - mark it
            // submitted now (heard aloud) so lockAnswers()'s "nothing left
            // to mark" check doesn't treat this answer as absent and
            // reveal immediately instead of opening it for marking below.
            $answer->update(['submitted_at' => now()]);
            $this->lockAnswers($room);
            $game = $room->fresh()->ddfGame;
        }

        if ($game->state !== DdfGameState::AnswerSubmitted) {
            throw ValidationException::withMessages(['answer' => ['Answers cannot be marked right now.']]);
        }

        $answer->refresh()->update(['is_correct' => $isCorrect, 'marked_at' => now()]);
        broadcast(new DdfAnswerMarked($answer->fresh()));

        $this->revealQuestionResult($room, skipped: false);
    }

    /** GM action: force the reveal now, leaving any still-unmarked answers as null (skipped). */
    public function skipQuestion(GameRoom $room): void
    {
        $game = $room->ddfGame;

        if (! in_array($game->state, [DdfGameState::Question, DdfGameState::AnswerSubmitted], true)) {
            throw ValidationException::withMessages(['room' => ['No question is currently active.']]);
        }

        if ($game->state === DdfGameState::Question) {
            $this->lockAnswers($room);
            $game = $game->fresh();
        }

        if ($game->state === DdfGameState::AnswerSubmitted) {
            $this->revealQuestionResult($room, skipped: true);
        }
    }

    private function revealQuestionResult(GameRoom $room, bool $skipped): void
    {
        $game = $room->ddfGame;
        $question = $game->currentQuestion;

        $answers = DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', $game->current_question_number)
            ->get();

        $results = $answers->map(fn (DdfAnswer $a) => [
            'room_player_id' => $a->room_player_id,
            'answer_text' => $a->answer_text,
            'is_correct' => $a->is_correct,
        ]);

        $game->update([
            'state' => DdfGameState::QuestionResult->value,
            'state_version' => $game->state_version + 1,
        ]);

        broadcast(new DdfQuestionResult($room, $question, $results, $skipped));
    }

    // ------------------------------------------------------------------
    // QuestionResult -> (loop) Question, or -> RoundComplete -> Voting
    // ------------------------------------------------------------------

    /**
     * GM action: advance to the next question. From RoundComplete (a new
     * voting cycle's first question, rounds_played_this_cycle already reset
     * to 0 by applyHeartLoss()) this starts one directly. From QuestionResult
     * it advances within the current cycle, or - once EVERY active player has
     * had rounds_per_voting turns this cycle - jumps straight into voting.
     */
    public function nextQuestion(GameRoom $room): void
    {
        $game = $room->ddfGame;

        if ($game->state === DdfGameState::RoundComplete) {
            $this->startNextQuestion($room);

            return;
        }

        if ($game->state !== DdfGameState::QuestionResult) {
            throw ValidationException::withMessages(['room' => ['There is no question result to advance from.']]);
        }

        // Kept as the "questions resolved this cycle" running counter, whose
        // === 0 state is the cycle-start sentinel (see startNextQuestion()).
        $game->update(['rounds_played_this_cycle' => $game->rounds_played_this_cycle + 1]);

        if (! $this->everyoneHadTheirTurns($room, $game)) {
            $this->startNextQuestion($room);

            return;
        }

        // Every active player has answered rounds_per_voting questions this
        // cycle - jump straight into voting, no round_complete gate and no GM
        // "Start voting" click. (round_complete is still produced after a vote
        // resolves, via applyHeartLoss().)
        $this->beginVoting($room->fresh());
    }

    /**
     * True once every active player has had at least rounds_per_voting turns
     * (ddf_answers rows) since the current cycle started. One row is created
     * per question for its turn player in startNextQuestion(), so this counts
     * turns had, whether the player actually answered, timed out, or skipped.
     */
    private function everyoneHadTheirTurns(GameRoom $room, DdfGame $game): bool
    {
        $active = $this->activePlayers($room);

        if ($active->isEmpty()) {
            return false;
        }

        $byPlayer = DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', '>=', $game->cycle_started_question_number)
            ->get()
            ->groupBy('room_player_id');

        return $active->every(
            fn (RoomPlayer $p) => ($byPlayer->get($p->id)?->count() ?? 0) >= $game->rounds_per_voting,
        );
    }

    private function transitionToRoundComplete(GameRoom $room): void
    {
        $game = $room->ddfGame;

        $game->update([
            'state' => DdfGameState::RoundComplete->value,
            'state_version' => $game->state_version + 1,
        ]);

        broadcast(new DdfRoundComplete($room, $game->rounds_per_voting));
    }

    /** GM action: start voting now, from RoundComplete (normal) or QuestionResult/Question (force early). */
    public function startVoting(GameRoom $room): void
    {
        $game = $room->ddfGame;

        if (! in_array($game->state, [DdfGameState::RoundComplete, DdfGameState::QuestionResult, DdfGameState::Question], true)) {
            throw ValidationException::withMessages(['room' => ['Voting cannot start right now.']]);
        }

        $this->beginVoting($room);
    }

    /**
     * The actual "open a voting phase" mutation, with no state guard -
     * shared by startVoting() (guarded, GM-triggered, always a fresh
     * non-revote phase) and the automatic revote continuation in
     * revealVotingResults(), which needs to open a new voting phase from
     * VotingResults itself, restricted to the tied candidates.
     *
     * @param  array<int, int>|null  $tieCandidatePlayerIds
     */
    private function beginVoting(GameRoom $room, ?array $tieCandidatePlayerIds = null): void
    {
        $game = $room->ddfGame;
        $active = $this->activePlayers($room);

        $game->update([
            'state' => DdfGameState::Voting->value,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => now(),
            'voting_round_number' => $game->voting_round_number + 1,
            'is_revote' => $tieCandidatePlayerIds !== null,
            'tie_candidate_player_ids' => $tieCandidatePlayerIds,
        ]);

        // Safe players still VOTE; they're only removed as targets. A revote
        // passes an explicit (already safe-free) tie set, so skip re-filtering.
        $voterIds = $active->pluck('id')->all();
        $targetIds = $tieCandidatePlayerIds ?? $this->eligibleTargetIds($room, $game);

        $game = $game->fresh();
        broadcast(new DdfVotingStarted($game, $voterIds, $targetIds));
        $this->dispatchTimer($game, $game->voting_timer_seconds);
    }

    // ------------------------------------------------------------------
    // Voting -> VotingResults -> LifeLost -> (maybe) Elimination
    // ------------------------------------------------------------------

    public function castVote(GameRoom $room, RoomPlayer $voter, RoomPlayer $target): void
    {
        $game = $room->ddfGame;

        if ($game->state !== DdfGameState::Voting) {
            throw ValidationException::withMessages(['vote' => ['Voting is not currently open.']]);
        }

        if ($voter->ddfState->is_eliminated) {
            throw ValidationException::withMessages(['vote' => ['Eliminated players cannot vote.']]);
        }

        if ($voter->id === $target->id) {
            throw ValidationException::withMessages(['vote' => ["You can't vote for yourself."]]);
        }

        $eligibleTargets = $game->is_revote
            ? ($game->tie_candidate_player_ids ?? [])
            : $this->eligibleTargetIds($room, $game);

        if (! in_array($target->id, $eligibleTargets, true)) {
            throw ValidationException::withMessages(['vote' => [
                $game->is_revote
                    ? 'You can only vote for one of the tied players.'
                    : 'That player is safe this round.',
            ]]);
        }

        $existing = DdfVote::where('game_room_id', $room->id)
            ->where('voting_round_number', $game->voting_round_number)
            ->where('voter_room_player_id', $voter->id)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages(['vote' => ['You already voted this round.']]);
        }

        $vote = DdfVote::create([
            'game_room_id' => $room->id,
            'voting_round_number' => $game->voting_round_number,
            'voter_room_player_id' => $voter->id,
            'target_room_player_id' => $target->id,
        ]);

        broadcast(new DdfVoteCastToGm($vote->load('room')));

        $votesCast = DdfVote::where('game_room_id', $room->id)
            ->where('voting_round_number', $game->voting_round_number)
            ->count();
        $totalEligible = $this->activePlayers($room)->count();

        broadcast(new DdfVotingProgress($room, $votesCast, $totalEligible));

        if ($votesCast >= $totalEligible) {
            $this->revealVotingResults($room);
        }
    }

    /** GM action: force the reveal now (equivalent to the voting timer expiring). */
    public function endVoting(GameRoom $room): void
    {
        if ($room->ddfGame->state !== DdfGameState::Voting) {
            throw ValidationException::withMessages(['room' => ['Voting is not currently open.']]);
        }

        $this->revealVotingResults($room);
    }

    private function revealVotingResults(GameRoom $room): void
    {
        $game = $room->ddfGame;

        if ($game->state !== DdfGameState::Voting) {
            return; // already resolved by the other trigger racing this one
        }

        $tally = DdfVote::where('game_room_id', $room->id)
            ->where('voting_round_number', $game->voting_round_number)
            ->selectRaw('target_room_player_id, count(*) as vote_count')
            ->groupBy('target_room_player_id')
            ->pluck('vote_count', 'target_room_player_id');

        $results = $this->activePlayers($room)->map(fn (RoomPlayer $p) => [
            'room_player_id' => $p->id,
            'vote_count' => (int) ($tally[$p->id] ?? 0),
        ]);

        // The broadcast tally shows every active player, but a safe player can
        // never be the one who loses a life - compute the max/top over the
        // eligible subset only. On a revote that subset is the tie set (which
        // was already built safe-free by the first reveal).
        $eligible = $game->is_revote
            ? ($game->tie_candidate_player_ids ?? $results->pluck('room_player_id')->all())
            : $this->eligibleTargetIds($room, $game);

        $eligibleResults = $results->whereIn('room_player_id', $eligible);
        $maxVotes = $eligibleResults->max('vote_count');
        $topPlayerIds = $eligibleResults->filter(fn ($r) => $r['vote_count'] === $maxVotes)->pluck('room_player_id')->values()->all();

        $game->update([
            'state' => DdfGameState::VotingResults->value,
            'state_version' => $game->state_version + 1,
        ]);

        if (count($topPlayerIds) === 1) {
            $resolvedBy = $game->is_revote ? DdfTieResolution::Revote : DdfTieResolution::Vote;

            broadcast(new DdfVotingResults($room, false, $resolvedBy->value, $topPlayerIds[0], [], false, $results));

            $loser = $room->players()->whereKey($topPlayerIds[0])->first();
            $this->applyHeartLoss($room, $loser, $resolvedBy);

            return;
        }

        if (! $game->is_revote) {
            broadcast(new DdfVotingResults($room, true, null, null, $topPlayerIds, false, $results));

            $this->beginVoting($room->fresh(), $topPlayerIds);

            return;
        }

        // Tied again after a revote - block until the GM decides.
        broadcast(new DdfVotingResults($room, true, null, null, $topPlayerIds, true, $results));
        broadcast(new DdfTieNeedsResolution($room, $topPlayerIds));
        $game->update(['tie_candidate_player_ids' => $topPlayerIds]);
    }

    /** GM action: only valid while VotingResults is blocked awaiting a still-tied decision. */
    public function resolveTie(GameRoom $room, RoomPlayer $loser): void
    {
        $game = $room->ddfGame;

        if ($game->state !== DdfGameState::VotingResults || $game->tie_candidate_player_ids === null) {
            throw ValidationException::withMessages(['room' => ['There is no tie to resolve right now.']]);
        }

        if (! in_array($loser->id, $game->tie_candidate_player_ids, true)) {
            throw ValidationException::withMessages(['room' => ['That player was not part of the tie.']]);
        }

        $game->update(['tie_candidate_player_ids' => null]);
        $this->applyHeartLoss($room, $loser, DdfTieResolution::GmDecision);
    }

    private function applyHeartLoss(GameRoom $room, RoomPlayer $loser, DdfTieResolution $resolvedBy): void
    {
        $state = $loser->ddfState;
        $hearts = max(0, $state->hearts - 1);

        $state->update(['hearts' => $hearts]);
        broadcast(new DdfLifeLost($room, $loser, $hearts));

        if ($hearts === 0) {
            $state->update(['is_eliminated' => true, 'eliminated_at' => now()]);
            broadcast(new DdfPlayerEliminated($room, $loser, 'hearts_zero'));
        }

        if ($this->checkWinCondition($room)) {
            return;
        }

        $room->ddfGame->update(['rounds_played_this_cycle' => 0]);
        $this->transitionToRoundComplete($room->fresh());
    }

    // ------------------------------------------------------------------
    // Elimination / win condition / game over
    // ------------------------------------------------------------------

    /** GM action: remove a player independent of hearts. */
    public function eliminatePlayer(GameRoom $room, RoomPlayer $player): void
    {
        if ($player->ddfState->is_eliminated) {
            return;
        }

        $player->ddfState->update(['is_eliminated' => true, 'eliminated_at' => now()]);
        broadcast(new DdfPlayerEliminated($room, $player, 'gm_removed'));

        $this->checkWinCondition($room);
    }

    private function checkWinCondition(GameRoom $room): bool
    {
        $active = $this->activePlayers($room);

        if ($active->count() > 1) {
            return false;
        }

        $this->finishGame($room, $active->first());

        return true;
    }

    private function finishGame(GameRoom $room, ?RoomPlayer $winner): void
    {
        $game = $room->ddfGame;

        $game->update([
            'state' => DdfGameState::GameOver->value,
            'state_version' => $game->state_version + 1,
            'winner_room_player_id' => $winner?->id,
        ]);

        broadcast(new DdfGameOver($game->fresh()->load('winner')));
    }

    /** GM action: force-end from any state. */
    public function endGame(GameRoom $room): void
    {
        $active = $this->activePlayers($room);
        $this->finishGame($room, $active->count() === 1 ? $active->first() : null);
    }

    // ------------------------------------------------------------------
    // Side channels: pause/resume, settings, restart
    // ------------------------------------------------------------------

    public function pause(GameRoom $room): void
    {
        $game = $room->ddfGame;

        if (! in_array($game->state, [DdfGameState::Question, DdfGameState::Voting], true)) {
            throw ValidationException::withMessages(['room' => ['Nothing to pause right now.']]);
        }

        if ($game->is_paused) {
            return;
        }

        $elapsed = now()->diffInSeconds($game->stage_started_at, absolute: true);
        $duration = $game->state === DdfGameState::Question ? $game->question_timer_seconds : $game->voting_timer_seconds;
        $remaining = max(0, $duration - $elapsed);

        $game->update([
            'is_paused' => true,
            'paused_remaining_seconds' => $remaining,
            'state_version' => $game->state_version + 1,
        ]);

        broadcast(new DdfGamePaused($room, $remaining));
    }

    public function resume(GameRoom $room): void
    {
        $game = $room->ddfGame;

        if (! $game->is_paused) {
            throw ValidationException::withMessages(['room' => ['The game is not paused.']]);
        }

        $remaining = $game->paused_remaining_seconds ?? 0;

        $game->update([
            'is_paused' => false,
            'paused_remaining_seconds' => null,
            'stage_started_at' => now(),
            'state_version' => $game->state_version + 1,
        ]);

        broadcast(new DdfGameResumed($room, $remaining));

        $game = $game->fresh();

        if (! ($game->state === DdfGameState::Question && $game->couch_mode)) {
            $this->dispatchTimer($game, $remaining);
        }
    }

    /** Allowed in Lobby, or anywhere before the *current* voting cycle starts. */
    public function updateSettings(GameRoom $room, array $data): void
    {
        $game = $room->ddfGame;

        $allowed = [
            DdfGameState::Lobby,
            DdfGameState::Question,
            DdfGameState::AnswerSubmitted,
            DdfGameState::QuestionResult,
            DdfGameState::RoundComplete,
        ];

        if (! in_array($game->state, $allowed, true)) {
            throw ValidationException::withMessages(['room' => ['Settings cannot be changed once voting has started this cycle.']]);
        }

        $game->update(array_intersect_key($data, array_flip([
            'rounds_per_voting', 'question_timer_seconds', 'voting_timer_seconds', 'language', 'couch_mode', 'safe_mode', 'dataset_id',
        ])));

        broadcast(new DdfSettingsUpdated($game->fresh()));
    }

    public function restart(GameRoom $room): void
    {
        $game = $room->ddfGame;

        $game->update([
            'state' => DdfGameState::Lobby->value,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => null,
            'rounds_played_this_cycle' => 0,
            'cycle_started_question_number' => 0,
            'current_question_id' => null,
            'current_question_number' => 0,
            'voting_round_number' => 0,
            'is_paused' => false,
            'paused_remaining_seconds' => null,
            'is_revote' => false,
            'tie_candidate_player_ids' => null,
            'winner_room_player_id' => null,
            'current_turn_room_player_id' => null,
        ]);

        foreach ($room->players as $player) {
            $player->ddfState->update([
                'hearts' => 3,
                'is_eliminated' => false,
                'eliminated_at' => null,
                'is_camera_ready' => false,
            ]);
        }

        broadcast(new DdfGameReset($room->fresh()));
    }

    // ------------------------------------------------------------------
    // Timer job entrypoint
    // ------------------------------------------------------------------

    /**
     * Invoked by AdvanceDdfGameState. Guarded by a locked, version-checked
     * read so a stale fire (superseded by a manual GM action or an
     * all-answered/all-voted completion that already advanced state) is a
     * safe no-op, exactly like RoundService::handleStageTimeout().
     */
    public function handleTimerExpired(int $ddfGameId, int $expectedVersion): void
    {
        $shouldAct = false;
        $state = null;
        $roomId = null;

        DB::transaction(function () use ($ddfGameId, $expectedVersion, &$shouldAct, &$state, &$roomId) {
            $game = DdfGame::lockForUpdate()->find($ddfGameId);

            if (! $game || $game->state_version !== $expectedVersion || $game->is_paused) {
                return;
            }

            $shouldAct = true;
            $state = $game->state;
            $roomId = $game->game_room_id;
        });

        if (! $shouldAct) {
            return;
        }

        $room = GameRoom::find($roomId);

        if (! $room) {
            return;
        }

        match ($state) {
            DdfGameState::GameStart => $this->startNextQuestion($room),
            DdfGameState::Question => $this->lockAnswers($room),
            DdfGameState::Voting => $this->revealVotingResults($room),
            default => null,
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function dispatchTimer(DdfGame $game, int $seconds): void
    {
        AdvanceDdfGameState::dispatch($game->id, $game->state_version)
            ->delay(now()->addSeconds($seconds));
    }

    /** @return Collection<int, RoomPlayer> */
    private function activePlayers(GameRoom $room): Collection
    {
        return $room->players()->whereHas('ddfState', fn ($q) => $q->where('is_eliminated', false))
            ->with('ddfState')
            ->get();
    }

    /**
     * Vote targets for a fresh (non-revote) voting phase. Every active player,
     * unless safe_mode is on - then a player who was asked at least one
     * question this voting cycle and got every one of them right is "safe" and
     * removed from the list (still allowed to vote, just not be voted for).
     * If that would leave nobody, safe mode yields for the round and the full
     * active list is returned.
     *
     * @return list<int>
     */
    private function eligibleTargetIds(GameRoom $room, DdfGame $game): array
    {
        $activeIds = $this->activePlayers($room)->pluck('id')->all();

        if (! $game->safe_mode) {
            return $activeIds;
        }

        $byPlayer = DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', '>=', $game->cycle_started_question_number)
            ->get()
            ->groupBy('room_player_id');

        $safe = [];

        foreach ($activeIds as $id) {
            $rows = $byPlayer->get($id);

            // A null (skipped / timed-out / ungraded) or false answer breaks
            // "aced everything"; zero rows this cycle isn't "aced" either.
            if ($rows && $rows->isNotEmpty() && $rows->every(fn (DdfAnswer $a) => $a->is_correct === true)) {
                $safe[] = $id;
            }
        }

        $eligible = array_values(array_diff($activeIds, $safe));

        return $eligible === [] ? $activeIds : $eligible;
    }

    /** Fixed turn order: active players by join order (id ascending), wrapping around. */
    private function nextTurnPlayer(GameRoom $room, ?int $afterPlayerId): RoomPlayer
    {
        $active = $this->activePlayers($room)->sortBy('id')->values();

        if ($afterPlayerId !== null) {
            $next = $active->first(fn (RoomPlayer $p) => $p->id > $afterPlayerId);

            if ($next) {
                return $next;
            }
        }

        return $active->first();
    }
}
