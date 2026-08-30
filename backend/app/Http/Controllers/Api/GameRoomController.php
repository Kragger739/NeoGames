<?php

namespace App\Http\Controllers\Api;

use App\Enums\DifficultyTier;
use App\Enums\GameMode;
use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Enums\SongGenre;
use App\Events\RoomReset;
use App\Events\RoomSettingsUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGameRoomRequest;
use App\Http\Requests\UpdateRoomSettingsRequest;
use App\Jobs\PrimeArtistSongPool;
use App\Models\GameRoom;
use App\Models\Guess;
use App\Models\RoomPlayer;
use App\Models\Round;
use App\Services\RoundService;
use App\Support\SongFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GameRoomController extends Controller
{
    public function store(StoreGameRoomRequest $request)
    {
        $mode = $request->validated('mode', GameMode::Classic->value);
        // Classic mode has no configurable settings - it always plays with
        // these fixed, "as intended" defaults, ignoring anything else the
        // request tries to submit for them (mirrors the genre-driven
        // year/artist clearing below, just keyed on mode instead).
        $isClassic = $mode === GameMode::Classic->value;
        $genre = $isClassic ? SongGenre::Iconic->value : $request->validated('genre', SongGenre::Normal->value);

        // Iconic is Classic-exclusive branding tied to its own curated
        // playlists (see SongGenre::spotifyPlaylistIds()) - never persist it
        // for any other mode, even if a request explicitly submits it.
        if (! $isClassic && $genre === SongGenre::Iconic->value) {
            $genre = SongGenre::Normal->value;
        }

        $datasetId = $request->validated('dataset_id') ?: null;

        if ($datasetId !== null) {
            // A custom dataset takes precedence over mode/genre entirely: one
            // flat pool of the user's imported tracks, a fixed round count,
            // no tiers.
            $selectionColumns = [
                'genre' => SongGenre::Normal->value,
                'year_from' => null,
                'year_to' => null,
                'artist_name' => null,
                'artist_names' => null,
                'songs_per_tier' => $request->validated('songs_per_tier', 10),
                'enabled_tiers' => [DifficultyTier::Easy->value],
                'guess_timeout_seconds' => $request->validated('guess_timeout_seconds', 8),
                'dataset_id' => $datasetId,
            ];
        } else {
            $selectionColumns = [
                'genre' => $genre,
                'year_from' => ! $isClassic && $genre === SongGenre::Year->value ? $request->validated('year_from') : null,
                'year_to' => ! $isClassic && $genre === SongGenre::Year->value ? $request->validated('year_to') : null,
                'artist_name' => ! $isClassic && $genre === SongGenre::Artist->value ? $request->validated('artist_name') : null,
                'artist_names' => ! $isClassic && $genre === SongGenre::MultiArtist->value ? $request->validated('artist_names') : null,
                'songs_per_tier' => $isClassic ? 1 : $request->validated('songs_per_tier', 3),
                'enabled_tiers' => $isClassic ? array_column(DifficultyTier::cases(), 'value') : $request->validated('enabled_tiers', array_column(DifficultyTier::cases(), 'value')),
                'guess_timeout_seconds' => $isClassic ? 8 : $request->validated('guess_timeout_seconds', 8),
                'dataset_id' => null,
            ];
        }

        $room = $request->user()->rooms()->create([
            'code' => GameRoom::generateUniqueCode(),
            'status' => RoomStatus::Lobby->value,
            'mode' => $mode,
            'player_mode' => $request->validated('player_mode', RoomPlayerMode::Multiplayer->value),
            'current_tier' => DifficultyTier::Easy->value,
            ...$selectionColumns,
        ]);

        if (in_array($room->genre, [SongGenre::Artist, SongGenre::MultiArtist], true)) {
            PrimeArtistSongPool::dispatch(SongFilter::fromRoom($room));
        }

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

    /**
     * Every song played this game, in play order, with whoever guessed it
     * correctly and how fast (the snippet stage playing at the moment of
     * their guess - the game's own "how fast" unit, since stages escalate
     * 0.1s/0.5s/1s/5s/15s until someone gets it). Public, same as show() -
     * anyone who was in the room, including anonymous nickname-only
     * players, should be able to review the results screen.
     */
    public function songHistory(Request $request, string $code)
    {
        $room = GameRoom::where('code', strtoupper($code))->firstOrFail();

        $rounds = $room->rounds()
            ->whereIn('status', ['won', 'failed'])
            ->with([
                'song',
                'guesses' => fn ($query) => $query
                    ->where('correct', true)
                    ->orderBy('snippet_stage_at_guess')
                    ->with('player.user:id,xp'),
            ])
            ->orderBy('id')
            ->get();

        $isBattleRoyale = $room->mode === GameMode::BattleRoyale;

        return response()->json([
            'rounds' => $rounds->map(function (Round $round) use ($isBattleRoyale) {
                // Classic/Solo only ever has one real winner
                // (winning_player_id) - a losing player's guess can still
                // land as `correct` in the guesses table if it arrives a
                // beat after the round already resolved (see
                // GuessService::submit()), so this filters back down to
                // just the actual winner rather than showing every
                // correct-but-too-late guess as if they'd won too. Battle
                // Royale genuinely scores every correct guesser
                // independently, so its full list stays as-is.
                $guesses = $isBattleRoyale
                    ? $round->guesses
                    : $round->guesses->where('player_id', $round->winning_player_id);

                return [
                    'round_id' => $round->id,
                    'song' => [
                        'title' => $round->song->title,
                        'artist' => $round->song->artist,
                        'album_art_url' => $round->song->album_art_url,
                        'provider_track_id' => $round->song->provider_track_id,
                    ],
                    'guessers' => $guesses->map(fn (Guess $guess) => [
                        'nickname' => $guess->player->nickname,
                        'level' => $guess->player->level,
                        'snippet_stage' => (float) $guess->snippet_stage_at_guess,
                    ])->values(),
                ];
            }),
        ]);
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
            'current_tier' => $room->firstEnabledTier()->value,
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

        if (($data['player_mode'] ?? null) === RoomPlayerMode::Solo->value && $room->players()->count() > 1) {
            throw ValidationException::withMessages([
                'player_mode' => ['Cannot switch to solo while other players are in the room.'],
            ]);
        }

        $effectiveDatasetId = array_key_exists('dataset_id', $data) ? $data['dataset_id'] : $room->dataset_id;

        if ($effectiveDatasetId !== null) {
            // Custom dataset drives selection - one flat pool, fixed round
            // count, no genre/year/artist/tiers. Bypasses all the mode/genre
            // forcing below.
            $data['dataset_id'] = $effectiveDatasetId;
            $data['genre'] = SongGenre::Normal->value;
            $data['year_from'] = null;
            $data['year_to'] = null;
            $data['artist_name'] = null;
            $data['artist_names'] = null;
            $data['enabled_tiers'] = [DifficultyTier::Easy->value];
            $data['songs_per_tier'] = $data['songs_per_tier'] ?? ($room->dataset_id !== null ? $room->songs_per_tier : 10);

            $room->update($data);
            broadcast(new RoomSettingsUpdated($room->fresh()));

            return response()->json($this->present($room->fresh()));
        }

        // Switching back to normal selection: restore sane tier defaults the
        // dataset had collapsed.
        if ($room->dataset_id !== null) {
            $data['enabled_tiers'] = $data['enabled_tiers'] ?? array_column(DifficultyTier::cases(), 'value');
            $data['songs_per_tier'] = $data['songs_per_tier'] ?? 3;
        }

        $effectiveMode = $data['mode'] ?? $room->mode->value;

        // Same forcing as store() - Classic has no configurable settings,
        // so any genre/tier/timeout the request tries to submit is
        // silently overridden back to the fixed defaults rather than
        // rejected, since a compliant client never sends them while in
        // Classic mode in the first place (see RoomSettingsForm.tsx).
        if ($effectiveMode === GameMode::Classic->value) {
            $data['genre'] = SongGenre::Iconic->value;
            $data['songs_per_tier'] = 1;
            $data['enabled_tiers'] = array_column(DifficultyTier::cases(), 'value');
            $data['guess_timeout_seconds'] = 8;
        } elseif (($data['genre'] ?? $room->genre->value) === SongGenre::Iconic->value) {
            // Iconic is Classic-exclusive - a stale value carried over from
            // switching mode away from Classic (see RoomSettingsForm.tsx)
            // must never persist for any other mode.
            $data['genre'] = SongGenre::Normal->value;
        }

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

        if ($effectiveGenre !== SongGenre::MultiArtist->value) {
            $data['artist_names'] = null;
        }

        $room->update($data);

        if (in_array($effectiveGenre, [SongGenre::Artist->value, SongGenre::MultiArtist->value], true)) {
            // Warms the relative-popularity pool while the host is still in
            // the lobby, so it's usually ready before they click Start -
            // RoundService::start() has its own synchronous safety net for
            // whatever this doesn't finish in time.
            PrimeArtistSongPool::dispatch(SongFilter::fromRoom($room->fresh()));
        }

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
            // Public (this endpoint needs no auth - anyone joining by code
            // hits it), but just an opaque id, same exposure level as the
            // player ids already in the `players` array below. Lets the
            // frontend tell "a host is logged in" apart from "the host
            // logged in right now owns this specific room" - see
            // LobbyPage.tsx's isHost.
            'host_id' => $room->host_id,
            'status' => $room->status->value,
            'mode' => $room->mode->value,
            'player_mode' => $room->player_mode->value,
            'genre' => $room->genre->value,
            'year_from' => $room->year_from,
            'year_to' => $room->year_to,
            'artist_name' => $room->artist_name,
            'artist_names' => $room->artist_names,
            'songs_per_tier' => $room->songs_per_tier,
            'enabled_tiers' => array_map(fn ($tier) => $tier->value, $room->enabledTiers()),
            'guess_timeout_seconds' => $room->guess_timeout_seconds,
            'dataset_id' => $room->dataset_id,
            'dataset_name' => $room->dataset?->name,
            'current_tier' => $room->current_tier?->value,
            'current_song_index' => $room->current_song_index,
            'players' => $room->players()
                ->orderByDesc('score')
                ->selectForSummary()
                ->get(),
            'current_round' => $currentRound ? [
                'round_id' => $currentRound->id,
                'audio_url' => $currentRound->song->audioUrl(),
                'stage' => (float) $currentRound->snippet_stage,
                'tier' => $currentRound->tier->value,
                'round_number' => $room->roundNumber(),
                'total_rounds' => $room->totalRounds(),
                'server_time' => $currentRound->stage_started_at->toIso8601String(),
            ] : null,
        ];
    }
}
