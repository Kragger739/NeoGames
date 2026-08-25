<?php

namespace Tests\Feature\GameFlow;

use App\Enums\DifficultyTier;
use App\Events\BattleRoyaleRoundResolved;
use App\Events\GameFinished;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Song;
use App\Models\User;
use App\Services\GuessService;
use App\Services\RoundService;
use App\Support\SnippetStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BattleRoyaleModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
    }

    private function seedSongsForAllTiers(int $count = 3): void
    {
        $this->fakeDeezerTrackRefresh();

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->count($count)->create();
        }
    }

    private function makePlayer(GameRoom $room, string $nickname): RoomPlayer
    {
        return $room->players()->create([
            'nickname' => $nickname,
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);
    }

    public function test_the_round_stays_open_after_only_some_active_players_guess_correctly(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'songs_per_tier' => 1]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $alice = $this->makePlayer($room, 'Alice');
        $this->makePlayer($room, 'Bob'); // hasn't answered yet

        $guessService = app(GuessService::class);
        $result = $guessService->submit($round, $alice, $round->song->title);

        $this->assertSame(['correct' => true, 'won' => true], $result);

        $round->refresh();
        $this->assertSame('playing', $round->status->value);
        $alice->refresh();
        $this->assertGreaterThan(0, $alice->score);

        Event::assertNotDispatched(BattleRoyaleRoundResolved::class);
    }

    public function test_the_round_closes_once_every_active_player_has_guessed_correctly(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'songs_per_tier' => 2]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $alice = $this->makePlayer($room, 'Alice');
        $bob = $this->makePlayer($room, 'Bob');

        $guessService = app(GuessService::class);
        $guessService->submit($round, $alice, $round->song->title);
        $guessService->submit($round, $bob, $round->song->title);

        $round->refresh();
        $this->assertSame('won', $round->status->value);
        $this->assertFalse($alice->fresh()->is_eliminated);
        $this->assertFalse($bob->fresh()->is_eliminated);

        Event::assertDispatched(
            BattleRoyaleRoundResolved::class,
            fn (BattleRoyaleRoundResolved $e) => $e->survivors->count() === 2 && $e->eliminated->count() === 0,
        );
    }

    public function test_the_final_stage_timeout_closes_the_round_and_eliminates_whoever_never_answered_correctly(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'songs_per_tier' => 2]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();
        $round->update(['snippet_stage' => SnippetStage::SEQUENCE[count(SnippetStage::SEQUENCE) - 1]]);

        $alice = $this->makePlayer($room, 'Alice');
        $bob = $this->makePlayer($room, 'Bob');

        app(GuessService::class)->submit($round, $alice, $round->song->title);

        app(RoundService::class)->handleStageTimeout($round->id, 1);

        $round->refresh();
        $this->assertSame('won', $round->status->value);
        $this->assertFalse($alice->fresh()->is_eliminated);
        $this->assertTrue($bob->fresh()->is_eliminated);

        Event::assertDispatched(
            BattleRoyaleRoundResolved::class,
            fn (BattleRoyaleRoundResolved $e) => $e->survivors->pluck('id')->all() === [$alice->id]
                && $e->eliminated->pluck('id')->all() === [$bob->id],
        );
    }

    public function test_a_full_wipe_when_nobody_answers_correctly_ends_the_game_immediately(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'songs_per_tier' => 1]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();
        $round->update(['snippet_stage' => SnippetStage::SEQUENCE[count(SnippetStage::SEQUENCE) - 1]]);

        $alice = $this->makePlayer($room, 'Alice');
        $bob = $this->makePlayer($room, 'Bob');

        app(RoundService::class)->handleStageTimeout($round->id, 1);

        $round->refresh();
        $this->assertSame('failed', $round->status->value);
        $this->assertTrue($alice->fresh()->is_eliminated);
        $this->assertTrue($bob->fresh()->is_eliminated);

        $room->refresh();
        $this->assertSame('finished', $room->status->value);
        Event::assertDispatched(GameFinished::class);
    }

    public function test_the_game_ends_immediately_once_only_one_active_player_remains(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'songs_per_tier' => 2]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();
        $round->update(['snippet_stage' => SnippetStage::SEQUENCE[count(SnippetStage::SEQUENCE) - 1]]);

        $alice = $this->makePlayer($room, 'Alice');
        $this->makePlayer($room, 'Bob');

        app(GuessService::class)->submit($round, $alice, $round->song->title);
        app(RoundService::class)->handleStageTimeout($round->id, 1);

        $room->refresh();
        $this->assertSame('finished', $room->status->value);
        Event::assertDispatched(GameFinished::class);
    }

    public function test_an_eliminated_player_cannot_submit_a_guess(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'songs_per_tier' => 2]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $eliminated = $this->makePlayer($room, 'Out');
        $eliminated->update(['is_eliminated' => true]);

        $response = $this->withHeader('X-Player-Token', $eliminated->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $round->song->title]);

        $response->assertUnprocessable();
    }

    public function test_a_stale_duplicate_close_trigger_is_a_no_op(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'songs_per_tier' => 2]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        // Alice is the only active player, so her single correct guess is
        // "everyone active has answered correctly" and closes the round
        // immediately - setting up the race this test actually checks for.
        $alice = $this->makePlayer($room, 'Alice');
        app(GuessService::class)->submit($round, $alice, $round->song->title);

        $round->refresh();
        $this->assertSame('won', $round->status->value);
        Event::assertDispatched(BattleRoyaleRoundResolved::class, 1);

        // Simulates the final-stage timeout landing right after the guess
        // that already closed the round (e.g. the last two triggers
        // racing) - must not double-close or double-broadcast.
        app(RoundService::class)->resolveBattleRoyaleRound($round);

        Event::assertDispatched(BattleRoyaleRoundResolved::class, 1);
    }

    public function test_each_correct_guesser_is_scored_independently(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'songs_per_tier' => 2]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $alice = $this->makePlayer($room, 'Alice');
        $bob = $this->makePlayer($room, 'Bob');

        $guessService = app(GuessService::class);
        $guessService->submit($round, $alice, $round->song->title);
        $guessService->submit($round, $bob, $round->song->title);

        $this->assertGreaterThan(0, $alice->fresh()->score);
        $this->assertGreaterThan(0, $bob->fresh()->score);
    }

    public function test_redoing_a_finished_room_clears_eliminations(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'status' => 'finished']);

        $eliminated = $this->makePlayer($room, 'Out');
        $eliminated->update(['is_eliminated' => true, 'score' => 50]);

        $this->actingAs($host)->postJson("/api/rooms/{$room->code}/redo")->assertOk();

        $this->assertFalse($eliminated->fresh()->is_eliminated);
        $this->assertSame(0, $eliminated->fresh()->score);
    }

    public function test_a_repeated_correct_guess_does_not_double_score(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'battle_royale', 'songs_per_tier' => 2]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $alice = $this->makePlayer($room, 'Alice');
        $this->makePlayer($room, 'Bob'); // keeps the round open past Alice's first guess

        $guessService = app(GuessService::class);
        $guessService->submit($round, $alice, $round->song->title);
        $scoreAfterFirst = $alice->fresh()->score;

        $guessService->submit($round, $alice, $round->song->title);
        $scoreAfterSecond = $alice->fresh()->score;

        $this->assertSame($scoreAfterFirst, $scoreAfterSecond);
    }
}
