<?php

namespace Tests\Feature\Season;

use App\Models\Cosmetic;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonProgress;
use App\Models\User;
use App\Services\LevelingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonXpTest extends TestCase
{
    use RefreshDatabase;

    private function activeSeason(): Season
    {
        return Season::create([
            'name' => 'Test Season',
            'slug' => 'test-season',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);
    }

    /** Seats each user as a linked player in descending score order, then finishes the game. */
    private function finishGameWith(User ...$users): Round
    {
        $room = GameRoom::factory()->create();
        $round = Round::factory()->for($room, 'room')->create();

        $score = 100;
        foreach ($users as $user) {
            RoomPlayer::factory()->for($room, 'room')->create(['user_id' => $user->id, 'score' => $score]);
            $score -= 10;
        }

        app(LevelingService::class)->awardForGameFinish($round);

        return $round;
    }

    public function test_finishing_a_game_awards_season_xp_by_placement(): void
    {
        $this->activeSeason();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->finishGameWith($first, $second);

        $this->assertSame(50, SeasonProgress::where('user_id', $first->id)->value('xp'));
        $this->assertSame(35, SeasonProgress::where('user_id', $second->id)->value('xp'));
    }

    public function test_no_active_season_is_a_silent_no_op(): void
    {
        $this->finishGameWith(User::factory()->create());

        $this->assertSame(0, SeasonProgress::count());
    }

    public function test_anonymous_players_get_no_season_progress(): void
    {
        $this->activeSeason();
        $room = GameRoom::factory()->create();
        $round = Round::factory()->for($room, 'room')->create();
        RoomPlayer::factory()->for($room, 'room')->create(['user_id' => null, 'score' => 100]);

        app(LevelingService::class)->awardForGameFinish($round);

        $this->assertSame(0, SeasonProgress::count());
    }

    public function test_crossing_a_threshold_bumps_the_tier_and_grants_its_cosmetic(): void
    {
        config(['seasons.tier_thresholds' => [40, 200, 300, 400, 500, 600, 700, 800, 900, 1000]]);
        $season = $this->activeSeason();
        $cosmetic = Cosmetic::create([
            'slot' => 'frame', 'key' => 'frame_t1', 'name' => 'Tier 1 Frame',
            'rarity' => 'common', 'source' => 'track', 'season_id' => $season->id, 'tier' => 1,
        ]);
        $user = User::factory()->create();

        $this->finishGameWith($user); // 50 season XP, clears the 40 threshold

        $this->assertSame(1, SeasonProgress::where('user_id', $user->id)->value('current_tier'));
        $this->assertDatabaseHas('cosmetic_user', [
            'user_id' => $user->id, 'cosmetic_id' => $cosmetic->id, 'source' => 'track',
        ]);
    }

    public function test_below_the_first_threshold_no_tier_is_unlocked(): void
    {
        $this->activeSeason(); // default thresholds: tier 1 @ 60
        $user = User::factory()->create();

        $this->finishGameWith($user); // only 50 season XP

        $this->assertSame(0, SeasonProgress::where('user_id', $user->id)->value('current_tier'));
        $this->assertDatabaseCount('cosmetic_user', 0);
    }

    public function test_re_running_a_finished_game_does_not_double_award(): void
    {
        $this->activeSeason();
        $room = GameRoom::factory()->create();
        $round = Round::factory()->for($room, 'room')->create();
        $user = User::factory()->create();
        RoomPlayer::factory()->for($room, 'room')->create(['user_id' => $user->id, 'score' => 100]);

        app(LevelingService::class)->awardForGameFinish($round);
        app(LevelingService::class)->awardForGameFinish($round->fresh());

        $this->assertSame(50, SeasonProgress::where('user_id', $user->id)->value('xp'));
    }
}
