<?php

namespace App\Http\Controllers\Api;

use App\Enums\DatasetType;
use App\Enums\RoomStatus;
use App\Events\Ddf\DdfPlayersUpdated;
use App\Http\Controllers\Controller;
use App\Models\Dataset;
use App\Models\DdfAnswer;
use App\Models\DdfGame;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Services\DdfGameService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Room lifecycle + every Game Master control-panel action for "Der Dümmste
 * fliegt". The GM is never seated as a RoomPlayer (see store()) - every GM
 * action here is authorized purely by GameRoom.host_id, mirroring
 * GameRoomController's own host-check pattern.
 */
class DdfGameController extends Controller
{
    public function store(Request $request, DdfGameService $service)
    {
        $dataset = $this->resolveDdfDataset($request);
        // A dataset carries its own language; otherwise the client picks one.
        $language = $dataset?->language ?? $request->input('language', 'en');

        if (! in_array($language, ['en', 'de'], true)) {
            throw ValidationException::withMessages(['language' => ['Choose English or German.']]);
        }

        $room = $request->user()->rooms()->create([
            'code' => GameRoom::generateUniqueCode(),
            'status' => RoomStatus::Lobby->value,
            'game' => 'ddf',
        ]);

        $room->ddfGame()->create([
            'state' => 'lobby',
            'rounds_per_voting' => $request->input('rounds_per_voting', 2),
            'question_timer_seconds' => $request->input('question_timer_seconds', 30),
            'voting_timer_seconds' => $request->input('voting_timer_seconds', 30),
            'language' => $language,
            'couch_mode' => $request->boolean('couch_mode', true),
            'safe_mode' => $request->boolean('safe_mode', false),
            'dataset_id' => $dataset?->id,
        ]);

        return response()->json($this->present($room->fresh()), 201);
    }

    /**
     * Resolves an optional `dataset_id` to a usable, non-empty DDF dataset,
     * or null (no id / blank id). Throws a 422 for anything unusable. Never
     * trusts the id for authorization - loads the row and checks the policy.
     */
    private function resolveDdfDataset(Request $request): ?Dataset
    {
        $id = $request->input('dataset_id');

        if (blank($id)) {
            return null;
        }

        $dataset = Dataset::find($id);

        if (! $dataset || $dataset->type !== DatasetType::Ddf || ! $request->user()->can('view', $dataset)) {
            throw ValidationException::withMessages(['dataset_id' => ['That question set isn’t available.']]);
        }

        if (! $dataset->questions()->exists()) {
            throw ValidationException::withMessages(['dataset_id' => ['That question set is empty — add questions to it first.']]);
        }

        return $dataset;
    }

    public function show(string $code)
    {
        $room = GameRoom::where('code', strtoupper($code))->where('game', 'ddf')->firstOrFail();

        return response()->json($this->present($room));
    }

    public function setReady(Request $request, string $code)
    {
        $room = $this->findRoom($code);
        $player = $this->playerFor($request, $room);

        $player->ddfState->update(['is_camera_ready' => (bool) $request->input('is_camera_ready')]);

        broadcast(new DdfPlayersUpdated($room));

        return response()->json(['is_camera_ready' => $player->ddfState->is_camera_ready]);
    }

    public function start(Request $request, string $code, DdfGameService $service)
    {
        $room = $this->authorizedRoom($request, $code);
        $service->start($room);

        return response()->json($this->present($room->fresh()));
    }

    public function pause(Request $request, string $code, DdfGameService $service)
    {
        $service->pause($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function resume(Request $request, string $code, DdfGameService $service)
    {
        $service->resume($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function nextQuestion(Request $request, string $code, DdfGameService $service)
    {
        $service->nextQuestion($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function rerollQuestion(Request $request, string $code, DdfGameService $service)
    {
        $service->rerollQuestion($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function updateSettings(Request $request, string $code, DdfGameService $service)
    {
        if ($request->has('language') && ! in_array($request->input('language'), ['en', 'de'], true)) {
            throw ValidationException::withMessages(['language' => ['Choose English or German.']]);
        }

        $data = $request->only([
            'rounds_per_voting', 'question_timer_seconds', 'voting_timer_seconds', 'language',
        ]);

        if ($request->has('couch_mode')) {
            $data['couch_mode'] = $request->boolean('couch_mode');
        }

        if ($request->has('safe_mode')) {
            $data['safe_mode'] = $request->boolean('safe_mode');
        }

        if ($request->has('dataset_id')) {
            $dataset = $this->resolveDdfDataset($request);
            $data['dataset_id'] = $dataset?->id;
            if ($dataset !== null) {
                $data['language'] = $dataset->language;
            }
        }

        $service->updateSettings($this->authorizedRoom($request, $code), $data);

        return response()->noContent();
    }

    /**
     * Marked by player id, not answer id - the frontend never learns a raw
     * ddf_answers PK from any broadcast event (DdfAnswerSubmittedToGm only
     * carries room_player_id), so resolving "the current question's answer
     * for this player" server-side avoids exposing one just for this call.
     */
    public function markAnswer(Request $request, string $code, int $playerId, DdfGameService $service)
    {
        $room = $this->authorizedRoom($request, $code);
        $game = $room->ddfGame;

        $answer = DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', $game->current_question_number)
            ->where('room_player_id', $playerId)
            ->firstOrFail();

        $service->markAnswer($room, $answer, (bool) $request->boolean('is_correct'));

        return response()->noContent();
    }

    public function skipQuestion(Request $request, string $code, DdfGameService $service)
    {
        $service->skipQuestion($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function startVoting(Request $request, string $code, DdfGameService $service)
    {
        $service->startVoting($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function endVoting(Request $request, string $code, DdfGameService $service)
    {
        $service->endVoting($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function resolveTie(Request $request, string $code, DdfGameService $service)
    {
        $room = $this->authorizedRoom($request, $code);
        $loser = $room->players()->findOrFail($request->input('loser_room_player_id'));

        $service->resolveTie($room, $loser);

        return response()->noContent();
    }

    public function eliminatePlayer(Request $request, string $code, int $playerId, DdfGameService $service)
    {
        $room = $this->authorizedRoom($request, $code);
        $player = $room->players()->findOrFail($playerId);

        $service->eliminatePlayer($room, $player);

        return response()->noContent();
    }

    public function restart(Request $request, string $code, DdfGameService $service)
    {
        $service->restart($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function end(Request $request, string $code, DdfGameService $service)
    {
        $service->endGame($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    private function findRoom(string $code): GameRoom
    {
        return GameRoom::where('code', strtoupper($code))->where('game', 'ddf')->firstOrFail();
    }

    private function authorizedRoom(Request $request, string $code): GameRoom
    {
        $room = $this->findRoom($code);

        if ($room->host_id !== $request->user()->id) {
            abort(403);
        }

        return $room;
    }

    /**
     * Resolves the RoomPlayer seat for a player-guard-authenticated
     * request, scoped to this room - a token from a different room's seat
     * (impossible in practice, since tokens are per-seat, but checked
     * explicitly rather than trusted) never resolves here.
     */
    private function playerFor(Request $request, GameRoom $room): RoomPlayer
    {
        $player = $request->user();

        if (! $player instanceof RoomPlayer || $player->room_id !== $room->id) {
            abort(403);
        }

        return $player;
    }

    private function present(GameRoom $room): array
    {
        $game = $room->ddfGame;

        return [
            'code' => $room->code,
            'host_id' => $room->host_id,
            'host_name' => $room->host->name,
            'state' => $game->state->value,
            'rounds_per_voting' => $game->rounds_per_voting,
            'rounds_played_this_cycle' => $game->rounds_played_this_cycle,
            'question_timer_seconds' => $game->question_timer_seconds,
            'voting_timer_seconds' => $game->voting_timer_seconds,
            'language' => $game->language->value,
            'couch_mode' => $game->couch_mode,
            'safe_mode' => $game->safe_mode,
            'dataset_id' => $game->dataset_id,
            'dataset_name' => $game->dataset?->name,
            'current_turn_room_player_id' => $game->current_turn_room_player_id,
            'current_question' => $game->currentQuestion ? [
                'id' => $game->currentQuestion->id,
                'text' => $game->currentQuestion->text,
                'category' => $game->currentQuestion->category->value,
                'number' => $game->current_question_number,
            ] : null,
            'is_paused' => $game->is_paused,
            'players' => $room->players()->selectForDdfSummary()->get()->map(fn (RoomPlayer $p) => [
                'room_player_id' => $p->id,
                'nickname' => $p->nickname,
                'hearts' => $p->ddfState->hearts,
                'is_eliminated' => $p->ddfState->is_eliminated,
                'is_camera_ready' => $p->ddfState->is_camera_ready,
                'level' => $p->level,
            ]),
            'winner_room_player_id' => $game->winner_room_player_id,
            'cycle_answers' => $this->cycleAnswers($room, $game),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * Per-player list of the questions asked SO FAR THIS VOTING CYCLE, in
     * order, with this player's correctness on each. Powers the red/green/grey
     * dot strip on every camera tile. Only the turn player gets a ddf_answers
     * row per question, so each player naturally maps to exactly the questions
     * where it was their turn this cycle. Public - the dots are the same for
     * everyone, not a GM secret.
     *
     * @return array<int, array<int, array{question_number: int, question_text: string, is_correct: bool|null}>>
     */
    private function cycleAnswers(GameRoom $room, DdfGame $game): array
    {
        return DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', '>=', $game->cycle_started_question_number)
            ->with('question:id,text')
            ->orderBy('question_number')
            ->get()
            ->groupBy('room_player_id')
            ->map(fn ($rows) => $rows->map(fn (DdfAnswer $a) => [
                'question_number' => $a->question_number,
                'question_text' => $a->question?->text ?? '',
                'is_correct' => $a->is_correct,
            ])->values())
            ->toArray();
    }

    /**
     * GM-only companion to show(): the current question's correct answer, the
     * cycle's answer history, and every player's raw submitted text. Kept off
     * the public show()/present() payload and on its own auth:sanctum route so
     * the answer key never reaches a player's client before the reveal.
     */
    public function gmState(Request $request, string $code)
    {
        $room = $this->authorizedRoom($request, $code);
        $game = $room->ddfGame;

        $gmAnswers = DdfAnswer::where('game_room_id', $room->id)
            ->where('question_number', $game->current_question_number)
            ->whereNotNull('submitted_at')
            ->with('player:id,nickname')
            ->get()
            ->map(fn (DdfAnswer $a) => [
                'room_player_id' => $a->room_player_id,
                'nickname' => $a->player?->nickname,
                'answer_text' => $a->answer_text,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ]);

        return response()->json([
            'correct_answer' => $game->currentQuestion?->correct_answer,
            'cycle_answers' => $this->cycleAnswers($room, $game),
            'gm_answers' => $gmAnswers,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
