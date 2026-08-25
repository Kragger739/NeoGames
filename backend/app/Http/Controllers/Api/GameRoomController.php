<?php

namespace App\Http\Controllers\Api;

use App\Enums\DifficultyTier;
use App\Enums\RoomStatus;
use App\Enums\SongGenre;
use App\Events\RoomReset;
use App\Events\RoomSettingsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGameRoomRequest;
use App\Http\Requests\UpdateRoomSettingsRequest;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Services\RoundService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GameRoomController extends Controller
{
    public function store(StoreGameRoomRequest $request)
    {
        $genre = $request->validated('genre', SongGenre::Normal->value);

        $room = $request->user()->rooms()->create([
            'code' => GameRoom::generateUniqueCode(),
            'status' => RoomStatus::Lobby->value,
            'mode' => $request->validated('mode', 'classic'),
            'genre' => $genre,
            'year_from' => $genre === SongGenre::Year->value ? $request->validated('year_from') : null,
            'year_to' => $genre === SongGenre::Year->value ? $request->validated('year_to') : null,
            'artist_name' => $genre === SongGenre::Artist->value ? $request->validated('artist_name') : null,
            'songs_per_tier' => $request->validated('songs_per_tier', 3),
            'guess_timeout_seconds' => $request->validated('guess_timeout_seconds', 8),
            'current_tier' => DifficultyTier::Easy->value,
        ]);

        // The host is automatically seated as a player too, so they can
        // guess alongside their friends instead of only moderating. Linking
        // user_id here is what lets the host earn XP for their own rounds.
        $hostPlayer = $room->players()->create([
            'user_id' => $request->user()->id,
            'nickname' => mb_substr($request->user()->username ?? $request->user()->name, 0, 20),
            'connection_token' => RoomPlayer::generateConnectionToken(),
            'score' => 0,
        ]);

        return response()->json([
            ...$this->present($room),
            'player' => [
                'id' => $hostPlayer->id,
                'connection_token' => $hostPlayer->connection_token,
                'nickname' => $hostPlayer->nickname,
            ],
        ], 201);
    }

    public function show(Request $request, string $code)
    {
        $room = GameRoom::where('code', strtoupper($code))->firstOrFail();

        return response()->json($this->present($room));
    }

    public function start(Request $request, string $code, RoundService $roundService)
    {
        $room = GameRoom::where('code', strtoupper($code))->firstOrFail();

        if ($room->host_id !== $request->user()->id) {
            abort(403);
        }

        if ($room->status !== RoomStatus::Lobby) {
            throw ValidationException::withMessages([
                'room' => ['This room has already started.'],
            ]);
        }

        try {
            $roundService->start($room);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['room' => [$e->getMessage()]]);
        }

        return response()->json($this->present($room->fresh()));
    }

    /**
     * Resets a finished room back to the lobby - same starting state as a
     * brand new room (Easy tier, song index 0, scores zeroed) - so the
     * host can start a fresh game without recreating the room. Round/guess
     * history is deliberately left alone: it just means startNextRound()
     * naturally avoids repeating a song from the previous playthrough
     * before eventually cycling back to it.
     */
    public function redo(Request $request, string $code)
    {
        $room = GameRoom::where('code', strtoupper($code))->firstOrFail();

        if ($room->host_id !== $request->user()->id) {
            abort(403);
        }

        if ($room->status !== RoomStatus::Finished) {
            throw ValidationException::withMessages([
                'room' => ['This room has not finished yet.'],
            ]);
        }

        $room->update([
            'status' => RoomStatus::Lobby->value,
            'current_tier' => DifficultyTier::Easy->value,
            'current_song_index' => 0,
        ]);

        // Battle Royale eliminations don't outlive the game they happened
        // in - a redo is a fresh start, same as scores zeroing.
        $room->players()->update(['score' => 0, 'is_eliminated' => false]);

        broadcast(new RoomReset($room->fresh()));

        return response()->json($this->present($room->fresh()));
    }

    /**
     * Lets the host tweak songs-per-tier/guess-timeout/mode/genre live
     * from the lobby - only while the room hasn't started yet, so a
     * running game's rules can't shift mid-play.
     */
    public function update(UpdateRoomSettingsRequest $request, string $code)
    {
        $room = GameRoom::where('code', strtoupper($code))->firstOrFail();

        if ($room->host_id !== $request->user()->id) {
            abort(403);
        }

        if ($room->status !== RoomStatus::Lobby) {
            throw ValidationException::withMessages([
                'room' => ['Settings can only be changed while the room is in the lobby.'],
            ]);
        }

        $data = $request->validated();
        $effectiveGenre = $data['genre'] ?? $room->genre->value;

        // A stale year range shouldn't linger once the host switches away
        // from Year mode - but only clear it when this request is actually
        // the one changing genre; a PATCH that only touches e.g.
        // songs_per_tier while the room is already in Year mode must leave
        // the existing range untouched.
        if ($effectiveGenre !== SongGenre::Year->value) {
            $data['year_from'] = null;
            $data['year_to'] = null;
        }

        // Same reasoning as the year range above - a stale artist name
        // shouldn't linger once the host switches away from Artist mode.
        if ($effectiveGenre !== SongGenre::Artist->value) {
            $data['artist_name'] = null;
        }

        $room->update($data);

        broadcast(new RoomSettingsUpdated($room->fresh()));

        return response()->json($this->present($room->fresh()));
    }

    /**
     * Includes enough live-round state (never the answer) for a
     * reconnecting/refreshing client to catch up without having missed the
     * broadcast that started it.
     *
     * @return array<string, mixed>
     */
    private function present(GameRoom $room): array
    {
        $currentRound = $room->rounds()
            ->where('status', 'playing')
            ->latest('id')
            ->first();

        return [
            'code' => $room->code,
            'status' => $room->status->value,
            'mode' => $room->mode->value,
            'genre' => $room->genre->value,
            'year_from' => $room->year_from,
            'year_to' => $room->year_to,
            'artist_name' => $room->artist_name,
            'songs_per_tier' => $room->songs_per_tier,
            'guess_timeout_seconds' => $room->guess_timeout_seconds,
            'current_tier' => $room->current_tier?->value,
            'current_song_index' => $room->current_song_index,
            'players' => $room->players()
                ->orderByDesc('score')
                ->get(['id', 'nickname', 'score', 'is_eliminated']),
            'current_round' => $currentRound ? [
                'round_id' => $currentRound->id,
                'audio_url' => $currentRound->song->audioUrl(),
                'stage' => (float) $currentRound->snippet_stage,
                'tier' => $currentRound->tier->value,
                'server_time' => $currentRound->stage_started_at->toIso8601String(),
            ] : null,
        ];
    }
}
