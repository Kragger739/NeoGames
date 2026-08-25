<?php

namespace Tests\Feature\GameFlow;

use App\Enums\DifficultyTier;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Song;
use App\Models\User;
use App\Services\RoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuessRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The HTTP layer's own "round already ended" guard means two truly
     * sequential HTTP requests can't reach this race at all - by the time
     * the second request re-fetches the round via route binding, it's
     * already 'won' and gets rejected before reaching GuessService. To
     * actually exercise the atomic-update race (proving logic correctness,
     * not true DB concurrency - Pest/PHPUnit run single-process), this
     * calls GuessService::submit() directly for both players against the
     * same in-memory $round, mirroring two requests that both read
     * status='playing' before either commits.
     */
    public function test_only_the_first_correct_guess_wins_when_two_players_answer_the_same_round(): void
    {
        Event::fake();
        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
        $this->fakeDeezerTrackRefresh();

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->create();
        }
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $alice = $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);
        $bob = $room->players()->create([
            'nickname' => 'Bob',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $guessService = app(\App\Services\GuessService::class);

        $firstResult = $guessService->submit($round, $alice, $round->song->title);
        $secondResult = $guessService->submit($round, $bob, $round->song->title);

        $this->assertSame(['correct' => true, 'won' => true], $firstResult);
        $this->assertSame(['correct' => true, 'won' => false], $secondResult);

        $round->refresh();
        $this->assertSame($alice->id, $round->winning_player_id);

        $alice->refresh();
        $bob->refresh();
        $this->assertGreaterThan(0, $alice->score);
        $this->assertSame(0, $bob->score);

        // Both guesses are recorded regardless of who won.
        $this->assertDatabaseHas('guesses', ['round_id' => $round->id, 'player_id' => $alice->id, 'correct' => true]);
        $this->assertDatabaseHas('guesses', ['round_id' => $round->id, 'player_id' => $bob->id, 'correct' => true]);
    }
}
