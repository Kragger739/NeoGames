<?php

namespace Tests\Feature\Leveling;

use App\Models\GameRoom;
use App\Models\NeoCoinEvent;
use App\Models\RoomPlayer;
use App\Models\Round;
use App\Models\User;
use App\Services\LevelingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NeoCoinLevelUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        config(['neocoins.per_level' => 50]);
    }

    private function finishSoloGameFor(User $user): Round
    {
        $room = GameRoom::factory()->create();
        $round = Round::factory()->for($room, 'room')->create();
        RoomPlayer::factory()->for($room, 'room')->create(['user_id' => $user->id, 'score' => 100]);

        app(LevelingService::class)->awardForGameFinish($round);

        return $round;
    }

    public function test_crossing_a_level_credits_neo_coins_once(): void
    {
        // xp 60 -> +50 (1st place) = 110, which is level 2 (threshold 100).
        $user = User::factory()->create(['xp' => 60]);

        $this->finishSoloGameFor($user);

        $user->refresh();
        $this->assertSame(2, $user->level);
        $this->assertSame(50, $user->neo_coins);
        $this->assertDatabaseHas('neo_coin_events', [
            'user_id' => $user->id,
            'amount' => 50,
            'reason' => 'level_up',
            'dedup_key' => "level_up:{$user->id}:2",
        ]);
        $this->assertSame(1, NeoCoinEvent::where('user_id', $user->id)->count());
    }

    public function test_replaying_the_same_finished_game_does_not_double_credit(): void
    {
        $user = User::factory()->create(['xp' => 60]);
        $round = $this->finishSoloGameFor($user);

        // Re-run the award for the same final round - XpEvent's UNIQUE makes
        // award() a no-op, so no second coin credit either.
        app(LevelingService::class)->awardForGameFinish($round);

        $this->assertSame(50, $user->fresh()->neo_coins);
        $this->assertSame(1, NeoCoinEvent::where('user_id', $user->id)->count());
    }

    public function test_a_multi_level_jump_credits_every_level_crossed(): void
    {
        config(['leveling.xp_first' => 250]);
        // xp 90 -> +250 = 340. Level 1 -> level 3 (thresholds 100 and 300).
        $user = User::factory()->create(['xp' => 90]);

        $this->finishSoloGameFor($user);

        $user->refresh();
        $this->assertSame(3, $user->level);
        $this->assertSame(100, $user->neo_coins);
        $this->assertSame(2, NeoCoinEvent::where('user_id', $user->id)->count());
    }

    public function test_no_level_up_means_no_coins(): void
    {
        // xp 0 -> +50 = 50, still level 1.
        $user = User::factory()->create(['xp' => 0]);

        $this->finishSoloGameFor($user);

        $user->refresh();
        $this->assertSame(1, $user->level);
        $this->assertSame(0, $user->neo_coins);
        $this->assertSame(0, NeoCoinEvent::where('user_id', $user->id)->count());
    }

    public function test_a_guest_earns_no_xp_and_no_coins(): void
    {
        $guest = User::factory()->create(['xp' => 60, 'is_guest' => true]);

        $this->finishSoloGameFor($guest);

        $guest->refresh();
        $this->assertSame(60, $guest->xp);
        $this->assertSame(0, $guest->neo_coins);
        $this->assertDatabaseCount('xp_events', 0);
        $this->assertDatabaseCount('neo_coin_events', 0);
    }
}
