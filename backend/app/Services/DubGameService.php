<?php

namespace App\Services;

use App\Enums\DubGameState;
use App\Enums\RoomPlayerMode;
use App\Events\Dub\DubAssembled;
use App\Events\Dub\DubAssemblyFailed;
use App\Events\Dub\DubAssemblyStarted;
use App\Events\Dub\DubClipSelected;
use App\Events\Dub\DubGameFinished;
use App\Events\Dub\DubGameReset;
use App\Events\Dub\DubLineAdvanced;
use App\Events\Dub\DubNextRound;
use App\Events\Dub\DubRatingProgress;
use App\Events\Dub\DubRatingStarted;
use App\Events\Dub\DubRecordingStarted;
use App\Events\Dub\DubRoleClaimStarted;
use App\Events\Dub\DubRoleClaimUpdated;
use App\Events\Dub\DubRoundScored;
use App\Events\Dub\DubTakeRecorded;
use App\Jobs\AdvanceDubState;
use App\Jobs\AssembleDubVideo;
use App\Models\DubClip;
use App\Models\DubClipCharacter;
use App\Models\DubClipLine;
use App\Models\DubGame;
use App\Models\DubRating;
use App\Models\DubTake;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Support\DubPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The sole writer of dub_games / dub_role_assignments / dub_takes /
 * dub_ratings state transitions - called from DubGameController (host +
 * player actions) and from AdvanceDubState (the one delayed timer job).
 * Every transition that opens a timed phase bumps state_version before
 * dispatching a new timer; every transition that closes one early also
 * bumps it so a still-pending job's later fire is a safe no-op - see
 * handleTimerExpired(). Assembling is job-completion-bound: AssembleDubVideo
 * calls onAssemblyComplete()/onAssemblyFailed() directly.
 */
class DubGameService
{
    public const MIN_PLAYERS = 2;

    // ------------------------------------------------------------------
    // Lobby
    // ------------------------------------------------------------------

    public function selectClip(GameRoom $room, DubClip $clip): void
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::Lobby) {
            throw ValidationException::withMessages(['clip' => ['The clip can only be chosen before the game starts.']]);
        }

        if (! $clip->isReady()) {
            throw ValidationException::withMessages(['clip' => ['That clip is still being prepared.']]);
        }

        $game->update(['dub_clip_id' => $clip->id]);

        broadcast(new DubClipSelected($game->fresh()->load('clip')));
    }

    public function start(GameRoom $room): void
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::Lobby) {
            throw ValidationException::withMessages(['room' => ['This game has already started.']]);
        }

        // A pack room hasn't picked a clip in the lobby - take the pack's
        // first finished clip as round 1.
        if ($room->dataset_id && ! $game->dub_clip_id) {
            $first = $room->dataset?->dubClips()->where('status', 'ready')->orderBy('position')->first();
            if ($first) {
                $game->update(['dub_clip_id' => $first->id]);
                $game = $game->fresh();
            }
        }

        $clip = $game->clip;

        if (! $clip || ! $clip->isReady()) {
            throw ValidationException::withMessages(['room' => ['Pick a prepared clip before starting.']]);
        }

        $players = $this->activePlayers($room);
        $solo = $this->isSolo($room);

        if ($players->count() < ($solo ? 1 : self::MIN_PLAYERS)) {
            throw ValidationException::withMessages(['room' => [
                $solo ? 'Join the room before starting.' : 'At least 2 players are needed to start.',
            ]]);
        }

        $notReady = $players->first(fn (RoomPlayer $p) => ! $p->dubState?->mic_ready);

        if ($notReady) {
            throw ValidationException::withMessages(['room' => ["Everyone needs a working mic first ({$notReady->nickname} isn't ready)."]]);
        }

        $game->update([
            'round_number' => 1,
            'current_line_index' => 0,
        ]);

        $this->beginRound($room, $game->fresh(), isStart: true);
    }

    /**
     * Shared "open a fresh round on the current clip" step for start() and
     * nextRound(). Multiplayer opens the interactive RoleClaim phase; solo
     * pre-assigns every character to the sole player and jumps straight to
     * Recording (there is nobody to claim against).
     */
    private function beginRound(GameRoom $room, DubGame $game, bool $isStart): void
    {
        $this->seedAssignments($game);

        if (! $this->isSolo($room)) {
            $game->update([
                'state' => DubGameState::RoleClaim->value,
                'state_version' => $game->state_version + 1,
                'stage_started_at' => now(),
            ]);

            $fresh = $game->fresh()->load('clip');
            broadcast($isStart ? new DubRoleClaimStarted($fresh) : new DubNextRound($fresh));

            return;
        }

        $solo = $room->players()->first();

        $game->roleAssignments()
            ->where('round_number', $game->round_number)
            ->update(['room_player_id' => $solo->id]);

        $game->update([
            'state' => DubGameState::Recording->value,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => now(),
            'current_line_index' => 0,
        ]);

        broadcast(new DubRecordingStarted($game->fresh()->load('clip')));
    }

    // ------------------------------------------------------------------
    // RoleClaim
    // ------------------------------------------------------------------

    public function claimRole(GameRoom $room, RoomPlayer $player, DubClipCharacter $character): void
    {
        $game = $this->guardRoleClaim($room, $character);

        DB::transaction(function () use ($game, $player, $character) {
            $target = $game->roleAssignments()
                ->where('round_number', $game->round_number)
                ->where('dub_clip_character_id', $character->id)
                ->lockForUpdate()
                ->first();

            if ($target && $target->room_player_id !== null && $target->room_player_id !== $player->id) {
                throw ValidationException::withMessages(['character' => ['Someone else already claimed that character.']]);
            }

            // One character per player: release whatever they held first.
            $game->roleAssignments()
                ->where('round_number', $game->round_number)
                ->where('room_player_id', $player->id)
                ->update(['room_player_id' => null]);

            $game->roleAssignments()->updateOrCreate(
                ['round_number' => $game->round_number, 'dub_clip_character_id' => $character->id],
                ['room_player_id' => $player->id],
            );
        });

        broadcast(new DubRoleClaimUpdated($game->fresh()));
    }

    public function unclaimRole(GameRoom $room, RoomPlayer $player, DubClipCharacter $character): void
    {
        $game = $this->guardRoleClaim($room, $character);

        $game->roleAssignments()
            ->where('round_number', $game->round_number)
            ->where('dub_clip_character_id', $character->id)
            ->where('room_player_id', $player->id)
            ->update(['room_player_id' => null]);

        broadcast(new DubRoleClaimUpdated($game->fresh()));
    }

    public function beginRecording(GameRoom $room): void
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::RoleClaim) {
            throw ValidationException::withMessages(['room' => ['Roles are not being claimed right now.']]);
        }

        $firstLine = $this->firstClaimedLinePosition($game, -1);

        if ($firstLine === null) {
            throw ValidationException::withMessages(['room' => ['Claim at least one character before recording.']]);
        }

        $game->update([
            'state' => DubGameState::Recording->value,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => now(),
            'current_line_index' => $firstLine,
        ]);

        $game = $game->fresh()->load('clip');
        broadcast(new DubRecordingStarted($game));
        $this->dispatchLineTimer($game);
    }

    // ------------------------------------------------------------------
    // Recording
    // ------------------------------------------------------------------

    public function submitTake(GameRoom $room, RoomPlayer $player, DubClipLine $line, string $audioPath, ?int $durationMs): DubTake
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::Recording) {
            throw ValidationException::withMessages(['take' => ['Recording is not open right now.']]);
        }

        if ($line->position !== $game->current_line_index) {
            throw ValidationException::withMessages(['take' => ['That line is not the one being recorded.']]);
        }

        $assignment = $game->roleAssignments()
            ->where('round_number', $game->round_number)
            ->where('dub_clip_character_id', $line->dub_clip_character_id)
            ->first();

        if (! $assignment || $assignment->room_player_id !== $player->id) {
            throw ValidationException::withMessages(['take' => ["This line isn't yours to record."]]);
        }

        $take = DB::transaction(function () use ($game, $player, $line, $audioPath, $durationMs) {
            $game->takes()
                ->where('round_number', $game->round_number)
                ->where('dub_clip_line_id', $line->id)
                ->update(['is_selected' => false]);

            return $game->takes()->create([
                'dub_clip_line_id' => $line->id,
                'room_player_id' => $player->id,
                'round_number' => $game->round_number,
                'audio_path' => $audioPath,
                'duration_ms' => $durationMs,
                'is_selected' => true,
                'created_at' => now(),
            ]);
        });

        broadcast(new DubTakeRecorded($take, $room->code));

        $this->advancePastLine($game);

        return $take;
    }

    /** Host action: skip the current line (it keeps its original audio) and move on. */
    public function advanceLine(GameRoom $room): void
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::Recording) {
            throw ValidationException::withMessages(['room' => ['Recording is not open right now.']]);
        }

        $this->advancePastLine($game);
    }

    private function advancePastLine(DubGame $game): void
    {
        $next = $this->firstClaimedLinePosition($game, $game->current_line_index);

        if ($next === null) {
            $this->transitionToAssembling($game);

            return;
        }

        $game->update([
            'current_line_index' => $next,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => now(),
        ]);

        $game = $game->fresh()->load('clip');
        broadcast(new DubLineAdvanced($game));
        $this->dispatchLineTimer($game);
    }

    // ------------------------------------------------------------------
    // Assembling
    // ------------------------------------------------------------------

    private function transitionToAssembling(DubGame $game): void
    {
        $game->update([
            'state' => DubGameState::Assembling->value,
            'state_version' => $game->state_version + 1,
            'assembly_started_at' => now(),
            'assembly_error' => null,
        ]);

        broadcast(new DubAssemblyStarted($game->fresh()));

        AssembleDubVideo::dispatch($game->id, $game->round_number);
    }

    public function onAssemblyComplete(DubGame $game, string $path): void
    {
        if ($game->state !== DubGameState::Assembling) {
            return;
        }

        $game->update([
            'state' => DubGameState::Watch->value,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => now(),
            'assembled_video_path' => $path,
            'assembly_error' => null,
        ]);

        $game = $game->fresh();
        broadcast(new DubAssembled($game));
        $this->dispatchWatchTimer($game);
    }

    public function onAssemblyFailed(DubGame $game, string $error): void
    {
        if ($game->state !== DubGameState::Assembling) {
            return;
        }

        $game->update([
            'state_version' => $game->state_version + 1,
            'assembly_error' => mb_substr($error, 0, 2000),
        ]);

        broadcast(new DubAssemblyFailed($game->fresh()));
    }

    public function retryAssembly(GameRoom $room): void
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::Assembling || $game->assembly_error === null) {
            throw ValidationException::withMessages(['room' => ['There is no failed assembly to retry.']]);
        }

        $game->update([
            'state_version' => $game->state_version + 1,
            'assembly_error' => null,
            'assembly_started_at' => now(),
        ]);

        AssembleDubVideo::dispatch($game->id, $game->round_number);
    }

    // ------------------------------------------------------------------
    // Watch -> Rating -> RoundComplete
    // ------------------------------------------------------------------

    public function markWatched(GameRoom $room): void
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::Watch) {
            return; // already advanced by the other trigger
        }

        // Solo has one rater and no competitive score - skip Rating and go
        // straight to RoundComplete with no score.
        if ($this->isSolo($room)) {
            $game->update([
                'state' => DubGameState::RoundComplete->value,
                'state_version' => $game->state_version + 1,
                'last_round_score' => null,
            ]);

            broadcast(new DubRoundScored($game->fresh(), []));

            return;
        }

        $game->update([
            'state' => DubGameState::Rating->value,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => now(),
        ]);

        broadcast(new DubRatingStarted($game->fresh()));
    }

    public function submitRating(GameRoom $room, RoomPlayer $player, int $score): void
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::Rating) {
            throw ValidationException::withMessages(['rating' => ['Ratings are not open right now.']]);
        }

        if ($score < 1 || $score > 5) {
            throw ValidationException::withMessages(['rating' => ['Pick a rating from 1 to 5.']]);
        }

        $existing = DubRating::where('dub_game_id', $game->id)
            ->where('room_player_id', $player->id)
            ->where('round_number', $game->round_number)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages(['rating' => ['You already rated this dub.']]);
        }

        DubRating::create([
            'dub_game_id' => $game->id,
            'room_player_id' => $player->id,
            'round_number' => $game->round_number,
            'score' => $score,
            'created_at' => now(),
        ]);

        $count = DubRating::where('dub_game_id', $game->id)->where('round_number', $game->round_number)->count();
        $total = $this->activePlayers($room)->count();

        broadcast(new DubRatingProgress($game, $count, $total));

        if ($count >= $total) {
            $this->scoreRound($room);
        }
    }

    /** Host action: close ratings now even if not everyone has voted. */
    public function forceScoreRound(GameRoom $room): void
    {
        if ($room->dubGame->state !== DubGameState::Rating) {
            throw ValidationException::withMessages(['room' => ['Ratings are not open right now.']]);
        }

        $this->scoreRound($room);
    }

    private function scoreRound(GameRoom $room): void
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::Rating) {
            return;
        }

        $ratings = DubRating::where('dub_game_id', $game->id)
            ->where('round_number', $game->round_number)
            ->get();

        $average = $ratings->isEmpty() ? 0.0 : round($ratings->avg('score'), 2);

        $game->update([
            'state' => DubGameState::RoundComplete->value,
            'state_version' => $game->state_version + 1,
            'last_round_score' => $average,
            'total_score' => round($game->total_score + $average, 2),
        ]);

        $byPlayer = $ratings->keyBy('room_player_id');

        $breakdown = $this->activePlayers($room)->map(fn (RoomPlayer $p) => [
            'room_player_id' => $p->id,
            'nickname' => $p->nickname,
            'score' => (int) ($byPlayer->get($p->id)?->score ?? 0),
        ])->values()->all();

        broadcast(new DubRoundScored($game->fresh(), $breakdown));
    }

    // ------------------------------------------------------------------
    // RoundComplete -> next round / finish
    // ------------------------------------------------------------------

    /**
     * Advance to the next clip. Single-clip rooms pass one in; pack rooms
     * pass null and the next unplayed `ready` pack clip is chosen (the game
     * finishes when the pack runs out).
     */
    public function nextRound(GameRoom $room, ?DubClip $clip = null): void
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::RoundComplete) {
            throw ValidationException::withMessages(['room' => ['Finish the current round first.']]);
        }

        if ($room->dataset_id) {
            $played = array_values(array_unique(array_merge($game->played_clip_ids ?? [], [$game->dub_clip_id])));

            $clip = $room->dataset?->dubClips()
                ->where('status', 'ready')
                ->whereNotIn('id', $played)
                ->orderBy('position')
                ->first();

            $game->update(['played_clip_ids' => $played]);

            if (! $clip) {
                $this->finish($room);

                return;
            }
        }

        if (! $clip || ! $clip->isReady()) {
            throw ValidationException::withMessages(['clip' => ['That clip is still being prepared.']]);
        }

        $game->update([
            'round_number' => $game->round_number + 1,
            'current_line_index' => 0,
            'dub_clip_id' => $clip->id,
            'assembled_video_path' => null,
            'assembly_error' => null,
            'last_round_score' => null,
        ]);

        $this->beginRound($room, $game->fresh(), isStart: false);
    }

    public function finish(GameRoom $room): void
    {
        $game = $room->dubGame;

        if ($game->state === DubGameState::Finished) {
            return;
        }

        $game->update([
            'state' => DubGameState::Finished->value,
            'state_version' => $game->state_version + 1,
        ]);

        broadcast(new DubGameFinished($game->fresh(), $this->roundsSummary($game->fresh())));
    }

    public function restart(GameRoom $room): void
    {
        $game = $room->dubGame;

        $game->roleAssignments()->delete();
        $game->takes()->delete();
        $game->ratings()->delete();

        $game->update([
            'state' => DubGameState::Lobby->value,
            'state_version' => $game->state_version + 1,
            'stage_started_at' => null,
            'round_number' => 0,
            'current_line_index' => 0,
            // A pack room re-picks its first clip on start(); a single-clip
            // room keeps the one it had.
            'dub_clip_id' => $room->dataset_id ? null : $game->dub_clip_id,
            'played_clip_ids' => null,
            'assembled_video_path' => null,
            'assembly_error' => null,
            'assembly_started_at' => null,
            'last_round_score' => null,
            'total_score' => 0,
        ]);

        foreach ($room->players as $player) {
            $player->dubState?->update(['mic_ready' => false, 'has_downloaded' => false]);
        }

        broadcast(new DubGameReset($room->fresh()));
    }

    // ------------------------------------------------------------------
    // Timer job entrypoint
    // ------------------------------------------------------------------

    public function handleTimerExpired(int $dubGameId, int $expectedVersion): void
    {
        $shouldAct = false;
        $state = null;
        $roomId = null;

        DB::transaction(function () use ($dubGameId, $expectedVersion, &$shouldAct, &$state, &$roomId) {
            $game = DubGame::lockForUpdate()->find($dubGameId);

            if (! $game || $game->state_version !== $expectedVersion) {
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
            DubGameState::Recording => $this->advanceLine($room),
            DubGameState::Watch => $this->markWatched($room),
            default => null,
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function guardRoleClaim(GameRoom $room, DubClipCharacter $character): DubGame
    {
        $game = $room->dubGame;

        if ($game->state !== DubGameState::RoleClaim) {
            throw ValidationException::withMessages(['room' => ['Roles are not being claimed right now.']]);
        }

        if ((int) $character->dub_clip_id !== (int) $game->dub_clip_id) {
            throw ValidationException::withMessages(['character' => ['That character is not in this round’s clip.']]);
        }

        return $game;
    }

    private function seedAssignments(DubGame $game): void
    {
        $clip = $game->clip;

        if (! $clip) {
            return;
        }

        foreach ($clip->characters as $character) {
            $game->roleAssignments()->updateOrCreate(
                ['round_number' => $game->round_number, 'dub_clip_character_id' => $character->id],
                ['room_player_id' => null],
            );
        }
    }

    /**
     * Position of the first line after $afterPosition whose character has
     * been claimed by a player this round, or null if there are none left.
     * Lines belonging to unclaimed characters are skipped - they keep their
     * original audio in the assembled dub.
     */
    private function firstClaimedLinePosition(DubGame $game, int $afterPosition): ?int
    {
        $claimedCharacterIds = $game->roleAssignments()
            ->where('round_number', $game->round_number)
            ->whereNotNull('room_player_id')
            ->pluck('dub_clip_character_id')
            ->all();

        if ($claimedCharacterIds === []) {
            return null;
        }

        $line = DubClipLine::where('dub_clip_id', $game->dub_clip_id)
            ->where('position', '>', $afterPosition)
            ->whereIn('dub_clip_character_id', $claimedCharacterIds)
            ->orderBy('position')
            ->first();

        return $line?->position;
    }

    private function isSolo(GameRoom $room): bool
    {
        return $room->player_mode === RoomPlayerMode::Solo;
    }

    /** @return Collection<int, RoomPlayer> */
    private function activePlayers(GameRoom $room): Collection
    {
        return $room->players()->with('dubState')->get();
    }

    private function dispatchLineTimer(DubGame $game): void
    {
        if ($game->line_timer_seconds > 0) {
            AdvanceDubState::dispatch($game->id, $game->state_version)
                ->delay(now()->addSeconds($game->line_timer_seconds));
        }
    }

    private function dispatchWatchTimer(DubGame $game): void
    {
        if ($game->watch_timer_seconds > 0) {
            AdvanceDubState::dispatch($game->id, $game->state_version)
                ->delay(now()->addSeconds($game->watch_timer_seconds));
        }
    }

    /** @return array<int, array{round_number: int, clip_title: string, score: float|null, video_url: string|null}> */
    private function roundsSummary(DubGame $game): array
    {
        // Phase 1: no per-round history table yet - report the final round only.
        return [[
            'round_number' => $game->round_number,
            'clip_title' => $game->clip?->title ?? '',
            'score' => $game->last_round_score,
            'video_url' => DubPresenter::url($game->assembled_video_path),
        ]];
    }
}
