<?php

namespace Tests\Feature\GameFlow;

use App\Enums\DifficultyTier;
use App\Events\GameFinished;
use App\Events\RevealSkipProgress;
use App\Events\RoundStarted;
use App\Jobs\AdvanceAfterReveal;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Round;
use App\Models\Song;
use App\Models\User;
use App\Services\GuessService;
use App\Services\RoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RevealSkipVoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        // Faking AdvanceAfterReveal keeps the reveal "on screen" - the sync
        // queue ignores delay(), so without this the round would advance
        // synchronously the instant it resolved and there'd be no reveal to
        // vote on.
        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class, AdvanceAfterReveal::class]);
        $this->fakeDeezerTrackRefresh();

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->count(3)->create();
        }
    }

    /**
     * @return array{0: GameRoom, 1: Collection<int, RoomPlayer>}
     */
    private function startedRoomWithPlayers(int $playerCount, array $roomAttrs = []): array
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create($roomAttrs);

        app(RoundService::class)->start($room);

        $players = collect(range(1, $playerCount))->map(fn (int $i) => $room->players()->create([
            'nickname' => "P{$i}",
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]));

        return [$room->fresh(), $players];
    }

    private function resolveCurrentRound(GameRoom $room, RoomPlayer $winner): Round
    {
        $round = $room->rounds()->where('status', 'playing')->latest('id')->firstOrFail();
        app(GuessService::class)->submit($round, $winner, $round->song->title);

        return $round->fresh();
    }

    public function test_the_reveal_advances_early_once_more_than_half_vote(): void
    {
        [$room, $players] = $this->startedRoomWithPlayers(3);
        $round = $this->resolveCurrentRound($room, $players[0]);

        $this->assertSame('won', $round->status->value);
        $this->assertNull($round->advanced_at);

        $service = app(RoundService::class);

        // The round resolving already queued the delayed reveal-timeout
        // advance; a sub-threshold vote must not queue another.
        Queue::assertPushed(AdvanceAfterReveal::class, 1);

        // 1 of 3 - not a majority yet.
        $service->voteSkipReveal($round, $players[0]);
        Event::assertDispatched(RevealSkipProgress::class, fn ($e) => $e->votesCast === 1 && $e->eligible === 3);
        Queue::assertPushed(AdvanceAfterReveal::class, 1);

        // 2 of 3 - past 50%: the immediate advance is queued alongside the
        // delayed one.
        $service->voteSkipReveal($round, $players[1]);
        Event::assertDispatched(RevealSkipProgress::class, fn ($e) => $e->votesCast === 2 && $e->eligible === 3);
        Queue::assertPushed(AdvanceAfterReveal::class, 2);
        Queue::assertPushed(AdvanceAfterReveal::class, fn ($job) => $job->resolvedRoundId === $round->id);

        // Run the advance the vote just kicked off.
        $service->advanceNow($round->id);

        $this->assertNotNull($round->fresh()->advanced_at);
        $this->assertSame(1, $room->fresh()->current_song_index);
        $this->assertSame(2, $room->rounds()->count());
        $this->assertTrue($room->rounds()->where('status', 'playing')->exists());
    }

    public function test_a_repeat_vote_from_the_same_player_is_ignored(): void
    {
        [$room, $players] = $this->startedRoomWithPlayers(3);
        $round = $this->resolveCurrentRound($room, $players[0]);

        $service = app(RoundService::class);
        $service->voteSkipReveal($round, $players[0]);
        $service->voteSkipReveal($round, $players[0]);
        $service->voteSkipReveal($round, $players[0]);

        $this->assertSame(1, $round->revealSkipVotes()->count());
    }

    public function test_votes_do_not_carry_into_the_next_round(): void
    {
        [$room, $players] = $this->startedRoomWithPlayers(3);
        $round1 = $this->resolveCurrentRound($room, $players[0]);

        $service = app(RoundService::class);
        $service->voteSkipReveal($round1, $players[0]);
        $service->voteSkipReveal($round1, $players[1]);
        $service->advanceNow($round1->id);

        $round2 = $room->rounds()->where('status', 'playing')->latest('id')->firstOrFail();
        $this->assertNotSame($round1->id, $round2->id);
        // The new round starts with a clean tally - round 1's votes don't
        // count toward it.
        $this->assertSame(0, $round2->revealSkipVotes()->count());
    }

    public function test_exactly_half_is_not_enough(): void
    {
        [$room, $players] = $this->startedRoomWithPlayers(4);
        $round = $this->resolveCurrentRound($room, $players[0]);

        $service = app(RoundService::class);

        // Just the delayed advance queued by the round resolving.
        Queue::assertPushed(AdvanceAfterReveal::class, 1);

        $service->voteSkipReveal($round, $players[0]);
        $service->voteSkipReveal($round, $players[1]); // 2 of 4 - exactly 50%
        Queue::assertPushed(AdvanceAfterReveal::class, 1);

        $service->voteSkipReveal($round, $players[2]); // 3 of 4 - over 50%
        Queue::assertPushed(AdvanceAfterReveal::class, 2);
    }

    public function test_a_stale_advance_after_the_game_moved_on_is_a_no_op(): void
    {
        [$room, $players] = $this->startedRoomWithPlayers(3);
        $round1 = $this->resolveCurrentRound($room, $players[0]);

        $service = app(RoundService::class);
        $service->voteSkipReveal($round1, $players[0]);
        $service->voteSkipReveal($round1, $players[1]);
        $service->advanceNow($round1->id);

        $roundsAfterSkip = $room->rounds()->count();

        // The delayed AdvanceAfterReveal that was queued when round 1
        // resolved now fires late - it must do nothing.
        $service->advanceNow($round1->id);

        $this->assertSame($roundsAfterSkip, $room->rounds()->count());
    }

    public function test_skipping_the_final_round_finishes_the_game(): void
    {
        [$room, $players] = $this->startedRoomWithPlayers(3, [
            'enabled_tiers' => [DifficultyTier::Easy->value],
            'songs_per_tier' => 1,
        ]);
        $round = $this->resolveCurrentRound($room, $players[0]);

        $service = app(RoundService::class);
        $service->voteSkipReveal($round, $players[0]);
        $service->voteSkipReveal($round, $players[1]);
        $service->advanceNow($round->id);

        $this->assertSame('finished', $room->fresh()->status->value);
        Event::assertDispatched(GameFinished::class);
        // Only the game's opening round was ever started - the skip finished
        // the game rather than starting another.
        Event::assertDispatched(RoundStarted::class, 1);
        $this->assertSame(1, $room->rounds()->count());
    }

    public function test_the_endpoint_records_a_vote_and_returns_no_content(): void
    {
        [$room, $players] = $this->startedRoomWithPlayers(3);
        $round = $this->resolveCurrentRound($room, $players[0]);

        $this->withHeader('X-Player-Token', $players[0]->connection_token)
            ->postJson("/api/rounds/{$round->id}/skip-reveal")
            ->assertNoContent();

        $this->assertDatabaseHas('round_reveal_skip_votes', [
            'round_id' => $round->id,
            'room_player_id' => $players[0]->id,
        ]);
    }

    public function test_a_player_from_another_room_cannot_vote(): void
    {
        [$room, $players] = $this->startedRoomWithPlayers(3);
        $round = $this->resolveCurrentRound($room, $players[0]);

        [$otherRoom, $otherPlayers] = $this->startedRoomWithPlayers(2);

        $this->withHeader('X-Player-Token', $otherPlayers[0]->connection_token)
            ->postJson("/api/rounds/{$round->id}/skip-reveal")
            ->assertForbidden();
    }
}
