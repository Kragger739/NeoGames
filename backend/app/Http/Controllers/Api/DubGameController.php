<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Events\Dub\DubPlayersUpdated;
use App\Http\Controllers\Controller;
use App\Models\DubClip;
use App\Models\DubClipCharacter;
use App\Models\DubClipLine;
use App\Models\DubRating;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Services\DubGameService;
use App\Support\DubPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Room lifecycle + every host and player action for "Dub Together". The
 * host IS seated as a RoomPlayer (like Songle, unlike DDF's Game Master),
 * so /rooms/{code}/join and /leave are reused as-is; everything else lives
 * under the /dub-rooms prefix. Host actions re-check GameRoom.host_id;
 * player actions run on the custom "player" guard.
 */
class DubGameController extends Controller
{
    public function store(Request $request)
    {
        $playerMode = $request->input('player_mode', RoomPlayerMode::Multiplayer->value);
        if (! in_array($playerMode, array_column(RoomPlayerMode::cases(), 'value'), true)) {
            $playerMode = RoomPlayerMode::Multiplayer->value;
        }
        $solo = $playerMode === RoomPlayerMode::Solo->value;

        // Solo play is self-paced - no per-line or watch countdown.
        $lineTimer = $solo ? 0 : (int) $request->input('line_timer_seconds', 0);
        $watchTimer = $solo ? 0 : (int) $request->input('watch_timer_seconds', 0);

        $room = $request->user()->rooms()->create([
            'code' => GameRoom::generateUniqueCode(),
            'status' => RoomStatus::Lobby->value,
            'game' => 'dub',
            'player_mode' => $playerMode,
        ]);

        $room->dubGame()->create([
            'state' => 'lobby',
            'line_timer_seconds' => max(0, min(120, $lineTimer)),
            'watch_timer_seconds' => max(0, min(120, $watchTimer)),
        ]);

        // Seat the host as a player so they can claim a character and record.
        $host = $room->players()->create([
            'user_id' => $request->user()->id,
            'nickname' => mb_substr($request->user()->username ?? $request->user()->name, 0, 20),
            'connection_token' => RoomPlayer::generateConnectionToken(),
            'score' => 0,
        ]);
        $host->dubState()->create(['mic_ready' => false]);

        return response()->json([
            ...$this->present($room->fresh(), $host),
            'host_player' => [
                'id' => $host->id,
                'nickname' => $host->nickname,
                'connection_token' => $host->connection_token,
            ],
        ], 201);
    }

    public function show(Request $request, string $code)
    {
        $room = $this->findRoom($code);
        $player = $request->user('player');
        $player = $player instanceof RoomPlayer && $player->room_id === $room->id ? $player : null;

        return response()->json($this->present($room, $player));
    }

    public function setMicReady(Request $request, string $code)
    {
        $room = $this->findRoom($code);
        $player = $this->playerFor($request, $room);

        $player->dubState()->update(['mic_ready' => $request->boolean('mic_ready')]);

        broadcast(new DubPlayersUpdated($room));

        return response()->json(['mic_ready' => (bool) $player->dubState->fresh()->mic_ready]);
    }

    public function selectClip(Request $request, string $code, DubGameService $service)
    {
        $room = $this->authorizedRoom($request, $code);
        $clip = DubClip::findOrFail($request->input('clip_id'));

        $service->selectClip($room, $clip);

        return response()->json($this->present($room->fresh()));
    }

    public function start(Request $request, string $code, DubGameService $service)
    {
        $room = $this->authorizedRoom($request, $code);
        $service->start($room);

        return response()->json($this->present($room->fresh()));
    }

    public function claimRole(Request $request, string $code, DubGameService $service)
    {
        $room = $this->findRoom($code);
        $player = $this->playerFor($request, $room);
        $character = DubClipCharacter::findOrFail($request->input('character_id'));

        $service->claimRole($room, $player, $character);

        return response()->noContent();
    }

    public function unclaimRole(Request $request, string $code, int $characterId, DubGameService $service)
    {
        $room = $this->findRoom($code);
        $player = $this->playerFor($request, $room);
        $character = DubClipCharacter::findOrFail($characterId);

        $service->unclaimRole($room, $player, $character);

        return response()->noContent();
    }

    public function beginRecording(Request $request, string $code, DubGameService $service)
    {
        $service->beginRecording($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    /**
     * PHASE 1: accepts an `audio_path` string pointing at an already-stored
     * file (tests use Storage::fake). Phase 4 swaps this for a real
     * multipart upload + ffmpeg normalization.
     */
    public function submitTake(Request $request, string $code, int $lineId, DubGameService $service)
    {
        $room = $this->findRoom($code);
        $player = $this->playerFor($request, $room);
        $line = DubClipLine::findOrFail($lineId);

        if ($request->hasFile('audio')) {
            $path = $request->file('audio')->store("dub/games/{$room->dubGame->id}/round-{$room->dubGame->round_number}/takes", 'public');
        } else {
            $path = (string) $request->input('audio_path', "dub/games/{$room->dubGame->id}/take-{$lineId}.webm");
        }

        $take = $service->submitTake($room, $player, $line, $path, $request->integer('duration_ms') ?: null);

        return response()->json(['take_id' => $take->id]);
    }

    public function advanceLine(Request $request, string $code, DubGameService $service)
    {
        $service->advanceLine($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function retryAssembly(Request $request, string $code, DubGameService $service)
    {
        $service->retryAssembly($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function markWatched(Request $request, string $code, DubGameService $service)
    {
        $room = $this->findRoom($code);
        $this->playerFor($request, $room); // any seated player may advance the watch
        $service->markWatched($room);

        return response()->noContent();
    }

    public function submitRating(Request $request, string $code, DubGameService $service)
    {
        $room = $this->findRoom($code);
        $player = $this->playerFor($request, $room);

        $service->submitRating($room, $player, (int) $request->input('score'));

        return response()->noContent();
    }

    public function endRating(Request $request, string $code, DubGameService $service)
    {
        $service->forceScoreRound($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function nextRound(Request $request, string $code, DubGameService $service)
    {
        $room = $this->authorizedRoom($request, $code);
        $clip = DubClip::findOrFail($request->input('clip_id'));

        $service->nextRound($room, $clip);

        return response()->noContent();
    }

    public function finish(Request $request, string $code, DubGameService $service)
    {
        $service->finish($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    public function restart(Request $request, string $code, DubGameService $service)
    {
        $service->restart($this->authorizedRoom($request, $code));

        return response()->noContent();
    }

    // ------------------------------------------------------------------

    private function findRoom(string $code): GameRoom
    {
        return GameRoom::where('code', strtoupper($code))->where('game', 'dub')->firstOrFail();
    }

    private function authorizedRoom(Request $request, string $code): GameRoom
    {
        $room = $this->findRoom($code);

        if ($room->host_id !== $request->user()->id) {
            abort(403);
        }

        return $room;
    }

    private function playerFor(Request $request, GameRoom $room): RoomPlayer
    {
        $player = $request->user();

        if (! $player instanceof RoomPlayer || $player->room_id !== $room->id) {
            abort(403);
        }

        return $player;
    }

    private function present(GameRoom $room, ?RoomPlayer $player = null): array
    {
        $game = $room->dubGame->load('clip');
        $current = $game->state->value === 'recording' ? DubPresenter::currentLine($game) : null;

        $mine = null;

        if ($player) {
            $mine = DubRating::where('dub_game_id', $game->id)
                ->where('room_player_id', $player->id)
                ->where('round_number', $game->round_number)
                ->value('score');
        }

        return [
            'code' => $room->code,
            'host_id' => $room->host_id,
            'host_name' => $room->host->name,
            'player_mode' => $room->player_mode->value,
            'state' => $game->state->value,
            'round_number' => $game->round_number,
            'total_score' => $game->total_score,
            'last_round_score' => $game->last_round_score,
            'line_timer_seconds' => $game->line_timer_seconds,
            'clip' => DubPresenter::clip($game->clip),
            'role_assignments' => DubPresenter::assignments($game),
            'current_line_index' => $game->current_line_index,
            'current_line' => $current['line'] ?? null,
            'assigned_room_player_id' => $current['assigned_room_player_id'] ?? null,
            'total_lines' => $game->clip?->lines()->count() ?? 0,
            'takes' => $game->takes()
                ->where('round_number', $game->round_number)
                ->where('is_selected', true)
                ->get()
                ->map(fn ($t) => [
                    'line_id' => $t->dub_clip_line_id,
                    'room_player_id' => $t->room_player_id,
                    'take_id' => $t->id,
                    'duration_ms' => $t->duration_ms,
                ]),
            'assembled_video_url' => $game->assembled_video_path
                ? Storage::disk('public')->url($game->assembled_video_path)
                : null,
            'assembly_error' => $game->assembly_error,
            'rating' => [
                'mine' => $mine !== null ? (int) $mine : null,
                'count' => DubRating::where('dub_game_id', $game->id)->where('round_number', $game->round_number)->count(),
                'total' => $room->players()->count(),
            ],
            'players' => DubPresenter::players($room),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
