<?php

namespace App\Services;

use App\Enums\GameMode;
use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Enums\RoundStatus;
use App\Events\BattleRoyaleRoundResolved;
use App\Events\GameFinished;
use App\Events\RevealSkipProgress;
use App\Events\RoundFailed;
use App\Events\RoundStageAdvanced;
use App\Events\RoundStarted;
use App\Events\TierAdvanced;
use App\Jobs\AdvanceAfterReveal;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\DailyChallengeAttempt;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Round;
use App\Models\RoundRevealSkipVote;
use App\Models\Song;
use App\Support\SnippetStage;
use App\Support\SongFilter;
use App\Support\SongSelectionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The sole writer of GameRoom's current_tier/current_song_index -
 * keeps the denormalized progress columns from drifting out of sync
 * with the rounds actually played.
 */
class RoundService
{
    /**
     * Pause between a round resolving (won/failed) and the next one
     * starting, so players have time to see the reveal: the full 15s
     * snippet replay plus a few seconds of confetti/entrance animation.
     */
    public const REVEAL_DELAY_SECONDS = 18;

    /** How many candidates to try before giving up on finding a playable song. */
    private const MAX_PLAYABILITY_ATTEMPTS = 3;

    public function __construct(
        private SongDiscoveryService $songDiscovery,
        private LevelingService $leveling,
    ) {}

    public function start(GameRoom $room): Round
    {
        if ($room->dataset_id === null && $room->genre->isArtistSourced()) {
            // Synchronous safety net in case Start is clicked before
            // PrimeArtistSongPool's background job (dispatched from
            // GameRoomController::update()) has finished warming the pool.
            // Deliberately BEFORE the status flip below: a Spotify/iTunes
            // failure here (rate limit, timeout) must leave the room
            // untouched in "lobby" rather than stuck in "active" with no
            // round ever created - findRandomSongForRelativeTier() also
            // self-heals via its own call to this same method, so skipping
            // it here on failure costs nothing but a retry.
            $this->songDiscovery->ensureArtistPoolReady(SongFilter::fromRoom($room));
        }

        $room->update([
            'status' => RoomStatus::Active->value,
            'current_tier' => $room->firstEnabledTier()->value,
            'current_song_index' => 0,
        ]);

        return $this->startNextRound($room);
    }

    public function startNextRound(GameRoom $room): Round
    {
        if ($room->daily_challenge_id !== null) {
            // Daily challenge: the song set is fixed for the day, played in
            // order - no discovery, no host no-repeat memory (its iconic
            // songs shouldn't crowd out the host's normal-game rotation).
            $song = $this->pickDailySong($room);
        } else {
            $filter = SongFilter::fromRoom($room);
            $context = $this->buildSelectionContext($room);
            $song = $this->pickPlayableSong($filter, $context);

            if (! $song) {
                throw new RuntimeException("Couldn't find a {$room->current_tier->value} song right now. Try again shortly.");
            }

            $song->update(['last_used_at' => now()]);

            // Host-scoped cross-game no-repeat memory - every mode records into
            // it (see buildSelectionContext() for where it's read back), so a
            // host replaying the same genre/artist pool isn't handed a wall of
            // songs they just heard.
            $room->host->songPlays()->syncWithoutDetaching([$song->id]);
        }

        $round = $room->rounds()->create([
            'song_id' => $song->id,
            'tier' => $room->current_tier->value,
            'snippet_stage' => SnippetStage::first(),
            'stage_started_at' => now(),
            'status' => 'playing',
            'stage_version' => 1,
        ]);

        $round->setRelation('room', $room);
        $round->setRelation('song', $song);

        // Debug trail so a bad/broken song can be reported and traced back
        // by round id alone (e.g. "round 492 played a dead clip") - see
        // storage/logs/laravel.log, searchable by "round.started" or the id.
        Log::info('round.started', [
            'round_id' => $round->id,
            'room_code' => $room->code,
            'song_id' => $song->id,
            'title' => $song->title,
            'artist' => $song->artist,
            'provider_track_id' => $song->provider_track_id,
            'genre' => $room->genre->value,
            'tier' => $room->current_tier->value,
        ]);

        broadcast(new RoundStarted($round));

        // Fire-and-forget: for an Artist / MultiArtist room this warms the
        // per-room pool from the named act's Spotify top tracks; for every
        // other genre the pool is owned by `php artisan songs:sync` and this
        // is a near-instant no-op (see ExpandSongPool::handle). A custom
        // dataset IS the pool, and the Daily challenge draws from a fixed
        // list, so neither has anything to grow.
        if ($room->dataset_id === null && $room->daily_challenge_id === null) {
            ExpandSongPool::dispatch(SongFilter::fromRoom($room));
        }

        // Solo (player_mode, not a GameMode - see RoomPlayerMode) has no
        // timer at all - stages only advance when the player guesses wrong
        // (see GuessService::submit()/escalateSoloStage()). Skipping the
        // dispatch entirely means handleStageTimeout() is simply never
        // invoked for a Solo round.
        if ($room->player_mode !== RoomPlayerMode::Solo) {
            // The guessing grace period starts once the clip has actually
            // finished playing, not concurrently with it - otherwise a
            // late, long stage (e.g. 15s) could be force-escalated before
            // its own audio even finishes.
            AdvanceRoundStage::dispatch($round->id, 1)
                ->delay(now()->addSeconds($round->snippet_stage + $room->guess_timeout_seconds));
        }

        return $round;
    }

    /**
     * Picks a song and confirms it actually has a preview URL on the row
     * (see SongDiscoveryService::ensurePlayable() - seeded iTunes URLs don't
     * expire, so this is just a guard against a genuinely blank row). A dead
     * candidate is excluded and a new one drawn, bounded to
     * MAX_PLAYABILITY_ATTEMPTS so one bad row can't block a round.
     */
    private function pickPlayableSong(SongFilter $filter, SongSelectionContext $context): ?Song
    {
        for ($attempt = 0; $attempt < self::MAX_PLAYABILITY_ATTEMPTS; $attempt++) {
            $song = $this->songDiscovery->findRandomSongForTier($filter, $context);

            if (! $song) {
                return null;
            }

            if ($this->songDiscovery->ensurePlayable($song)) {
                return $song;
            }

            $context = $context->withExcludedTrack($song->provider_track_id);
        }

        return null;
    }

    /**
     * The Daily challenge's song for the round in progress - taken straight
     * from the challenge's fixed, ordered list by current_song_index.
     */
    private function pickDailySong(GameRoom $room): Song
    {
        $ids = $room->dailyChallenge->song_ids ?? [];
        $songId = $ids[$room->current_song_index] ?? null;
        $song = $songId ? Song::find($songId) : null;

        if (! $song || ! $this->songDiscovery->ensurePlayable($song)) {
            throw new RuntimeException('Today’s daily song is unavailable. Try again shortly.');
        }

        $song->update(['last_used_at' => now()]);

        return $song;
    }

    /**
     * Everything SongDiscoveryService's session-aware picker needs to know
     * about this room's game so far: which exact songs and artists have
     * already been used (so it can avoid repeats), and how many songs from
     * each SongEra bucket have been played (so it can bias the next pick
     * toward whichever bucket is furthest behind the game's target mix -
     * see SongSelectionContext::neediestEra()).
     */
    private function buildSelectionContext(GameRoom $room): SongSelectionContext
    {
        $usedSongs = Song::whereIn('id', $room->rounds()->pluck('song_id'))->get();

        $eraCounts = [];

        foreach ($usedSongs as $song) {
            $era = $song->eraBucket();

            if ($era !== null) {
                $eraCounts[$era->value] = ($eraCounts[$era->value] ?? 0) + 1;
            }
        }

        $excludeTrackIds = $usedSongs->pluck('provider_track_id')->all();

        // Host-scoped cross-game no-repeat preference (see User::songPlays()),
        // applied in every mode - merged in on top of this game's own used
        // songs, but still just a preference: the existing fallback chain
        // below (pickFallback()/relaxationLevels()) already reuses an
        // excluded track as a last resort rather than ever blocking a
        // round, which is what keeps this soft instead of a hard cap - and
        // is what lets a small Artist/MultiArtist pool keep starting rounds
        // once its history covers every track.
        $excludeTrackIds = array_unique(array_merge(
            $excludeTrackIds,
            $room->host->songPlays()->pluck('provider_track_id')->all(),
        ));

        return new SongSelectionContext(
            excludeTrackIds: $excludeTrackIds,
            usedArtistProviderIds: $usedSongs->pluck('artist_provider_id')->filter()->values()->all(),
            eraCounts: $eraCounts,
        );
    }

    /**
     * Populates the reveal screen's artist-follower stat before a round's
     * outcome broadcasts - a no-op once the seeded count is on the row, and
     * deliberately not blocking the round itself (unlike ensurePlayable()),
     * since it's purely cosmetic.
     */
    public function ensureRevealStats(Round $round): void
    {
        $this->songDiscovery->ensureFollowerCount($round->song);
    }

    /**
     * Called after a round resolves (won or failed). Schedules the move to
     * the next song / tier / game-finish for after the reveal window. The
     * decision itself is deferred to advanceNow() so this delayed path and
     * the >50% skip-reveal vote share one guarded code path.
     */
    public function advanceAfterRoundResolved(Round $round): void
    {
        AdvanceAfterReveal::dispatch($round->id)->delay(now()->addSeconds(self::REVEAL_DELAY_SECONDS));
    }

    /**
     * The single guarded "the reveal for a just-resolved round is over -
     * move the game forward" path. Reached from the AdvanceAfterReveal job,
     * which is dispatched twice for the same $resolvedRoundId: with an
     * 18s delay when the round resolved, and with no delay once more than
     * half the room votes to skip. The atomic advanced_at claim below lets
     * exactly one of those calls do the work; every other call - including
     * a stale delayed job that fires after the skipped-to round has itself
     * already resolved - is a safe no-op.
     */
    public function advanceNow(int $resolvedRoundId): void
    {
        $claimed = Round::query()
            ->where('id', $resolvedRoundId)
            ->whereNull('advanced_at')
            ->whereIn('status', ['won', 'failed'])
            ->update(['advanced_at' => now()]);

        if (! $claimed) {
            return;
        }

        $round = Round::with('room')->find($resolvedRoundId);

        if (! $round || ! $round->room) {
            return;
        }

        $room = $round->room;

        if ($room->status !== RoomStatus::Active) {
            return;
        }

        try {
            // Battle Royale wipe / last player standing: finish, don't start
            // another round.
            if ($room->mode === GameMode::BattleRoyale && $room->activePlayers()->count() <= 1) {
                $this->finishGame($room, $round->id);

                return;
            }

            $nextIndex = $room->current_song_index + 1;

            if ($nextIndex < $room->songs_per_tier) {
                $room->update(['current_song_index' => $nextIndex]);
                $this->startNextRound($room);

                return;
            }

            $nextTier = $room->nextEnabledTier();

            if ($nextTier === null) {
                $this->finishGame($room, $round->id);

                return;
            }

            $room->update([
                'current_tier' => $nextTier->value,
                'current_song_index' => 0,
            ]);

            broadcast(new TierAdvanced($room->fresh()));

            $this->startNextRound($room);
        } catch (\Throwable $e) {
            // startNextRound() can throw (song discovery / network). Release
            // the claim so the queue's retry can genuinely re-attempt,
            // instead of the latch freezing the game on a transient error.
            Round::whereKey($resolvedRoundId)->update(['advanced_at' => null]);

            throw $e;
        }
    }

    /**
     * A seated, non-eliminated player votes to end the current
     * between-rounds reveal early. Once strictly more than 50% of the
     * currently-eligible players have voted for this round, the advance
     * runs immediately through the same advanceNow() the delayed job uses.
     * Safe to call on a still-playing or already-advanced round, or from an
     * eliminated player - all no-op.
     */
    public function voteSkipReveal(Round $round, RoomPlayer $player): void
    {
        $round->refresh();

        if ($round->status === RoundStatus::Playing || $round->advanced_at !== null) {
            return;
        }

        if ($player->is_eliminated) {
            return;
        }

        // Idempotent via the unique(round_id, room_player_id) index - a
        // repeat tap inserts zero rows. insertOrIgnore() skips timestamps,
        // so set them explicitly.
        RoundRevealSkipVote::query()->insertOrIgnore([
            'round_id' => $round->id,
            'room_player_id' => $player->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $room = $round->room;

        // Numerator and denominator come from the same live activePlayers()
        // set, so a voter who left or was eliminated after voting drops out
        // of both.
        $eligibleIds = $room->activePlayers()->pluck('id');
        $eligible = $eligibleIds->count();

        $votesCast = RoundRevealSkipVote::query()
            ->where('round_id', $round->id)
            ->whereIn('room_player_id', $eligibleIds)
            ->count();

        broadcast(new RevealSkipProgress($room, $round->id, $votesCast, $eligible));

        // Strictly more than half (integer math, no float compare).
        if ($eligible > 0 && $votesCast * 2 > $eligible) {
            // No delay. The already-queued 18s AdvanceAfterReveal for this
            // same round becomes a no-op when it later fires.
            AdvanceAfterReveal::dispatch($round->id);
        }
    }

    /**
     * Actually ends the game: marks the room finished, awards placement XP,
     * and broadcasts GameFinished - invoked from advanceNow() (via the
     * delayed AdvanceAfterReveal job or a skip-reveal vote) rather than the
     * instant the final round resolves, so the room's status (and the
     * frontend's navigation to /results, which is keyed off it) doesn't
     * flip while the last round's reveal card is still meant to be on
     * screen - the same reveal window every other round already gets.
     */
    public function finishGame(GameRoom $room, int $roundId): void
    {
        $room->update(['status' => RoomStatus::Finished->value]);

        $round = Round::find($roundId);

        if ($round) {
            $this->leveling->awardForGameFinish($round);
        }

        if ($room->daily_challenge_id !== null) {
            $hostPlayer = $room->players()->where('user_id', $room->host_id)->first();

            DailyChallengeAttempt::where([
                'daily_challenge_id' => $room->daily_challenge_id,
                'user_id' => $room->host_id,
            ])->update([
                'score' => $hostPlayer?->score ?? 0,
                'correct_count' => $room->rounds()->where('status', 'won')->count(),
                'finished_at' => now(),
            ]);
        }

        broadcast(new GameFinished($room->fresh()));

        // Every 80th finished game (any mode), clear the host's no-repeat
        // memory (User::songPlays()) so their rotation starts fresh instead
        // of the pool of already-seen songs only ever growing - query-based
        // rather than a separate counter column, so it can never drift out
        // of sync with the room history it reflects.
        $finishedGames = GameRoom::where('host_id', $room->host_id)
            ->where('status', RoomStatus::Finished->value)
            ->count();

        if ($finishedGames % 80 === 0) {
            $room->host->songPlays()->detach();
        }
    }

    /**
     * Invoked by the AdvanceRoundStage job. $expectedStageVersion guards
     * against acting on a round that was already won or already advanced
     * by another timer - both make this a safe no-op.
     */
    public function handleStageTimeout(int $roundId, int $expectedStageVersion): void
    {
        [$shouldFail, $didAdvance] = $this->transitionStage($roundId, $expectedStageVersion);

        if (! $shouldFail && ! $didAdvance) {
            // Stale call: the round was already resolved, or a newer timer
            // already advanced/failed it. Safe no-op.
            return;
        }

        $round = Round::find($roundId);

        if (! $round) {
            return;
        }

        if ($shouldFail) {
            if ($round->room->mode === GameMode::BattleRoyale) {
                $this->resolveBattleRoyaleRound($round);

                return;
            }

            $this->ensureRevealStats($round);
            broadcast(new RoundFailed($round));
            $this->advanceAfterRoundResolved($round);

            return;
        }

        broadcast(new RoundStageAdvanced($round));

        // Same reasoning as startNextRound(): wait out the new stage's own
        // clip length before the guessing grace period starts counting.
        AdvanceRoundStage::dispatch($round->id, $round->stage_version)
            ->delay(now()->addSeconds($round->snippet_stage + $round->room->guess_timeout_seconds));
    }

    /**
     * Locks and advances (or fails, at the last stage) a round's snippet
     * stage - shared by the timer-driven path (handleStageTimeout) and
     * Solo's guess-driven path (escalateSoloStage). $expectedStageVersion
     * guards against acting on a round already resolved or already
     * advanced by another caller.
     *
     * @return array{0: bool, 1: bool} [$shouldFail, $didAdvance]
     */
    private function transitionStage(int $roundId, int $expectedStageVersion): array
    {
        $shouldFail = false;
        $didAdvance = false;

        DB::transaction(function () use ($roundId, $expectedStageVersion, &$shouldFail, &$didAdvance) {
            $round = Round::lockForUpdate()->find($roundId);

            if (! $round || $round->status->value !== 'playing' || $round->stage_version !== $expectedStageVersion) {
                return;
            }

            $nextStage = SnippetStage::next((float) $round->snippet_stage);

            if ($nextStage === null) {
                $shouldFail = true;

                // Battle Royale decides won/failed for itself based on who
                // answered correctly (resolveBattleRoyaleRound), which
                // needs status to still read 'playing' when it takes its
                // own lock right after this - leaving it untouched here
                // makes that method's update the one and only status
                // write for the round, instead of racing this one.
                if ($round->room->mode !== GameMode::BattleRoyale) {
                    $round->update(['status' => 'failed']);
                }

                return;
            }

            $round->update([
                'snippet_stage' => $nextStage,
                'stage_started_at' => now(),
                'stage_version' => $round->stage_version + 1,
            ]);
            $didAdvance = true;
        });

        return [$shouldFail, $didAdvance];
    }

    /**
     * Solo's replacement for the timer: called synchronously from
     * GuessService when a Solo player guesses wrong, instead of waiting on
     * a delayed job. No AdvanceRoundStage gets (re-)dispatched - Solo never
     * schedules timers at all.
     *
     * @return array{correct: bool, won: bool}
     */
    public function escalateSoloStage(Round $round): array
    {
        [$shouldFail, $didAdvance] = $this->transitionStage($round->id, $round->stage_version);

        if (! $shouldFail && ! $didAdvance) {
            return ['correct' => false, 'won' => false];
        }

        $round = Round::find($round->id);

        if ($shouldFail) {
            $this->ensureRevealStats($round);
            broadcast(new RoundFailed($round));
            $this->advanceAfterRoundResolved($round);
        } else {
            broadcast(new RoundStageAdvanced($round));
        }

        return ['correct' => false, 'won' => false];
    }

    /**
     * Closes a Battle Royale round: whoever guessed correctly survives,
     * everyone else currently active is eliminated for the rest of the
     * game (this can eliminate everyone at once - no "wash" special case).
     * Can be reached two ways at once - every active player guessing
     * correctly, or the final stage's timeout firing - so the actual
     * close is locked to guarantee it only ever runs once per round.
     */
    public function resolveBattleRoyaleRound(Round $round): void
    {
        $closed = false;
        $correctIds = null;

        DB::transaction(function () use ($round, &$closed, &$correctIds) {
            $locked = Round::lockForUpdate()->find($round->id);

            if (! $locked || $locked->status->value !== 'playing') {
                return;
            }

            $correctIds = $locked->correctGuesserIds();
            $locked->update(['status' => $correctIds->isNotEmpty() ? 'won' : 'failed']);
            $closed = true;
        });

        if (! $closed) {
            // Stale/duplicate call - another trigger already closed this
            // round (e.g. the final guess needed and the stage timeout
            // landed at the same time). Safe no-op.
            return;
        }

        $round = Round::find($round->id);
        $room = $round->room;

        $survivors = $room->activePlayers()->whereIn('id', $correctIds)->selectForSummary()->get();
        $eliminated = $room->activePlayers()->whereNotIn('id', $correctIds)->selectForSummary()->get();

        if ($eliminated->isNotEmpty()) {
            $room->players()->whereIn('id', $eliminated->pluck('id'))->update(['is_eliminated' => true]);
        }

        $this->ensureRevealStats($round);
        broadcast(new BattleRoyaleRoundResolved($round, $survivors, $eliminated));

        // Covers both "one player left" (they win) and "zero left" (a full
        // wipe) - either way the existing score-sorted scoreboard is all
        // GameFinished needs to show the result, no separate "declare
        // winner" step required. advanceNow() (reached via the delayed job
        // or a skip-reveal vote) re-checks activePlayers() and finishes the
        // game instead of starting a round when <= 1 remain, so both paths
        // stay skippable like every other reveal.
        $this->advanceAfterRoundResolved($round);
    }
}
