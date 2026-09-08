<?php

namespace Tests\Feature\Season;

use App\Models\GameRoom;
use App\Models\IconicArtist;
use App\Models\NeoCoinEvent;
use App\Models\RoomPlayer;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonProgress;
use App\Models\User;
use App\Services\LevelingService;
use App\Services\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BattlePassRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        config(['neocoins.per_level' => 50]);
    }

    private function activeSeason(): Season
    {
        return Season::create([
            'name' => 'Test Season',
            'slug' => 'test-season',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ]);
    }

    private function finishGameWith(User $user): Round
    {
        $room = GameRoom::factory()->create();
        $round = Round::factory()->for($room, 'room')->create();
        RoomPlayer::factory()->for($room, 'room')->create(['user_id' => $user->id, 'score' => 100]);
        app(LevelingService::class)->awardForGameFinish($round);

        return $round;
    }

    public function test_crossing_a_tier_grants_its_free_coins_and_iconic_artist_once(): void
    {
        $season = $this->activeSeason();
        $artist = IconicArtist::factory()->create(['price' => 500]);
        $season->tiers()->create([
            'tier' => 1,
            'xp_threshold' => 40,
            'free_coins' => 200,
            'free_iconic_artist_id' => $artist->id,
        ]);

        $user = User::factory()->create();
        $round = $this->finishGameWith($user); // 50 season xp -> clears tier 1

        $this->assertSame(1, SeasonProgress::where('user_id', $user->id)->value('current_tier'));
        $this->assertSame(200, $user->fresh()->neo_coins);
        $this->assertDatabaseHas('iconic_artist_user', [
            'user_id' => $user->id, 'iconic_artist_id' => $artist->id, 'source' => 'battlepass',
        ]);
        $this->assertSame(1, NeoCoinEvent::where('reason', 'battlepass')->count());

        // Replaying the finished game must not re-pay the tier.
        app(LevelingService::class)->awardForGameFinish($round);
        $this->assertSame(200, $user->fresh()->neo_coins);
        $this->assertSame(1, NeoCoinEvent::where('reason', 'battlepass')->count());
    }

    public function test_premium_coins_need_the_pass_and_replay_idempotently_on_toggle(): void
    {
        $season = $this->activeSeason();
        $season->tiers()->create([
            'tier' => 1,
            'xp_threshold' => 40,
            'premium_coins' => 300,
        ]);

        $user = User::factory()->create();
        $this->finishGameWith($user); // crosses tier 1 without a pass

        $this->assertSame(0, $user->fresh()->neo_coins); // premium withheld

        $seasons = app(SeasonService::class);
        $seasons->grantPass($user->id, $season);
        $this->assertSame(300, $user->fresh()->neo_coins);

        // Toggle the pass off and back on - the bp:...:premium dedup key means
        // no second payout.
        SeasonProgress::where('user_id', $user->id)->update(['has_pass' => false]);
        $seasons->grantPass($user->id, $season);
        $this->assertSame(300, $user->fresh()->neo_coins);
    }
}
