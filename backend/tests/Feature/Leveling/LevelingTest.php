<?php

namespace Tests\Feature\Leveling;

use App\Enums\DifficultyTier;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\Round;
use App\Models\RoomPlayer;
use App\Models\Song;
use App\Models\User;
use App\Models\XpEvent;
use App\Services\LevelingService;
use App\Services\RoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LevelingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // StartNextRound is deliberately left real (not faked) - the
        // multi-tier tests below need it to actually run so the game can
        // progress from round to round. Only the timeout/pool-growth jobs
        // are faked, same as RoundLifecycleTest.
        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
        // These tests assert on xp_events/users.xp, not on broadcasts -
        // fake every broadcastable event so guess/timeout calls don't try
        // to reach a real Reverb server.
        Event::fake();
    }

    private function hostWithFullLibrary(int $songsPerTier = 1): User
    {
        $this->fakeDeezerTrackRefresh();

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->count($songsPerTier)->create();
        }

        return User::factory()->create();
    }

    public function test_placements_get_fixed_xp_and_the_rest_get_participation(): void
    {
        $room = GameRoom::factory()->create();
        $round = Round::factory()->for($room, 'room')->create();

        $first = RoomPlayer::factory()->for($room, 'room')->create(['user_id' => User::factory()->create()->id, 'score' => 100]);
        // Anonymous, but still the second-highest score - occupies that
        // placement slot without earning anything itself.
        RoomPlayer::factory()->for($room, 'room')->create(['user_id' => null, 'score' => 90]);
        $third = RoomPlayer::factory()->for($room, 'room')->create(['user_id' => User::factory()->create()->id, 'score' => 80]);
        $fourth = RoomPlayer::factory()->for($room, 'room')->create(['user_id' => User::factory()->create()->id, 'score' => 70]);

        app(LevelingService::class)->awardForGameFinish($round);

        $first->user->refresh();
        $third->user->refresh();
        $fourth->user->refresh();

        $this->assertSame(50, $first->user->xp);
        // Ranks 3rd by position (behind the anonymous 2nd-place score),
        // not 2nd, despite being the second *linked* player.
        $this->assertSame(25, $third->user->xp);
        $this->assertSame(10, $fourth->user->xp);

        $this->assertDatabaseHas('xp_events', ['user_id' => $first->user_id, 'round_id' => $round->id, 'type' => 'first', 'amount' => 50]);
        $this->assertDatabaseHas('xp_events', ['user_id' => $third->user_id, 'round_id' => $round->id, 'type' => 'third', 'amount' => 25]);
        $this->assertDatabaseHas('xp_events', ['user_id' => $fourth->user_id, 'round_id' => $round->id, 'type' => 'participation', 'amount' => 10]);
        $this->assertSame(3, XpEvent::count()); // never 4 - the anonymous player earns nothing.
    }

    public function test_no_xp_is_awarded_until_the_whole_game_finishes(): void
    {
        $host = $this->hostWithFullLibrary();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        app(RoundService::class)->start($room);

        $playerUser = User::factory()->create();
        $player = $room->players()->create([
            'user_id' => $playerUser->id,
            'nickname' => 'Player',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $round = $room->rounds()->first();
        $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $round->song->title])
            ->assertOk();

        // Easy is won, but Intermediate/Medium/Hard/Extreme are still ahead -
        // the game hasn't finished, so no XP should exist yet.
        $playerUser->refresh();
        $this->assertSame(0, $playerUser->xp);
        $this->assertSame(0, XpEvent::count());

        foreach (array_slice(DifficultyTier::ordered(), 1) as $tier) {
            $round = $room->fresh()->rounds()->where('status', 'playing')->firstOrFail();

            $this->withHeader('X-Player-Token', $player->connection_token)
                ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $round->song->title])
                ->assertOk();
        }

        $room->refresh();
        $this->assertSame('finished', $room->status->value);

        $playerUser->refresh();
        $this->assertGreaterThan(0, $playerUser->xp);
        $this->assertSame(1, XpEvent::where('user_id', $playerUser->id)->where('type', 'first')->count());
    }

    public function test_anonymous_players_earn_no_xp(): void
    {
        $host = $this->hostWithFullLibrary();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $anon = $room->players()->create([
            'nickname' => 'Anon',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $this->withHeader('X-Player-Token', $anon->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $round->song->title])
            ->assertOk();

        $this->assertSame(0, XpEvent::count());
    }

    public function test_it_does_not_double_award_xp_for_the_same_game(): void
    {
        $room = GameRoom::factory()->create();
        $round = Round::factory()->for($room, 'room')->create();
        $player = RoomPlayer::factory()->for($room, 'room')->create(['user_id' => User::factory()->create()->id, 'score' => 100]);

        app(LevelingService::class)->awardForGameFinish($round);
        $player->user->refresh();
        $this->assertSame(50, $player->user->xp);

        // Directly re-invoke the same award for the same finished game -
        // simulates advanceAfterRoundResolved somehow firing twice.
        app(LevelingService::class)->awardForGameFinish($round->fresh());

        $player->user->refresh();
        $this->assertSame(50, $player->user->xp);
        $this->assertSame(1, XpEvent::where('user_id', $player->user_id)->count());
    }

    public function test_the_api_user_endpoint_reports_username_xp_and_level(): void
    {
        $host = User::factory()->create(['username' => 'leveluser', 'xp' => 300]);

        $response = $this->actingAs($host)->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('username', 'leveluser');
        $response->assertJsonPath('xp', 300);
        $response->assertJsonPath('level', 3);
    }

    public function test_level_for_xp_boundaries(): void
    {
        $leveling = app(LevelingService::class);

        $this->assertSame(1, $leveling->levelForXp(0));
        $this->assertSame(1, $leveling->levelForXp(99));
        $this->assertSame(2, $leveling->levelForXp(100));
        $this->assertSame(2, $leveling->levelForXp(299));
        $this->assertSame(3, $leveling->levelForXp(300));
        $this->assertSame(3, $leveling->levelForXp(599));
        $this->assertSame(4, $leveling->levelForXp(600));
    }
}
