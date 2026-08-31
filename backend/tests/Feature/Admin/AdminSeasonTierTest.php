<?php

namespace Tests\Feature\Admin;

use App\Models\Cosmetic;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Round;
use App\Models\Season;
use App\Models\SeasonProgress;
use App\Models\User;
use App\Services\LevelingService;
use App\Services\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSeasonTierTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    private function season(): Season
    {
        return Season::create([
            'name' => 'S', 'slug' => 's',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(30),
        ]);
    }

    private function cosmetic(string $key): Cosmetic
    {
        return Cosmetic::create([
            'slot' => 'frame', 'key' => $key, 'name' => $key, 'rarity' => 'common', 'source' => 'track',
        ]);
    }

    private function finishSoloGame(User $user): void
    {
        $room = GameRoom::factory()->create();
        $round = Round::factory()->for($room, 'room')->create();
        RoomPlayer::factory()->for($room, 'room')->create(['user_id' => $user->id, 'score' => 100]);
        app(LevelingService::class)->awardForGameFinish($round);
    }

    public function test_put_tiers_replaces_the_whole_ladder(): void
    {
        $season = $this->season();
        $season->tiers()->create(['tier' => 1, 'xp_threshold' => 999]);
        $free = $this->cosmetic('free1');

        $this->actingAs($this->admin())->putJson("/api/admin/seasons/{$season->id}/tiers", [
            'tiers' => [
                ['xp_threshold' => 30, 'free_cosmetic_id' => $free->id],
                ['xp_threshold' => 120],
            ],
        ])->assertOk()->assertJsonPath('tier_count', 2);

        $this->assertSame(2, $season->tiers()->count());
        $this->assertSame(30, $season->tiers()->where('tier', 1)->value('xp_threshold'));
        $this->assertNull($season->tiers()->where('tier', 999)->value('id'));
    }

    public function test_it_rejects_non_ascending_thresholds(): void
    {
        $season = $this->season();

        $this->actingAs($this->admin())->putJson("/api/admin/seasons/{$season->id}/tiers", [
            'tiers' => [['xp_threshold' => 100], ['xp_threshold' => 100]],
        ])->assertUnprocessable();
    }

    public function test_it_rejects_an_unknown_cosmetic_id(): void
    {
        $season = $this->season();

        $this->actingAs($this->admin())->putJson("/api/admin/seasons/{$season->id}/tiers", [
            'tiers' => [['xp_threshold' => 50, 'free_cosmetic_id' => 424242]],
        ])->assertUnprocessable();
    }

    public function test_the_new_ladder_drives_free_unlocks_on_the_next_award(): void
    {
        $season = $this->season();
        $free = $this->cosmetic('free1');
        $season->tiers()->create(['tier' => 1, 'xp_threshold' => 40, 'free_cosmetic_id' => $free->id]);

        $user = User::factory()->create();
        $this->finishSoloGame($user); // 50 season XP -> clears 40

        $this->assertSame(1, SeasonProgress::where('user_id', $user->id)->value('current_tier'));
        $this->assertDatabaseHas('cosmetic_user', [
            'user_id' => $user->id, 'cosmetic_id' => $free->id, 'source' => 'track',
        ]);
    }

    public function test_premium_rewards_are_withheld_without_the_pass_and_granted_with_it(): void
    {
        $season = $this->season();
        $free = $this->cosmetic('free1');
        $premium = $this->cosmetic('prem1');
        $season->tiers()->create([
            'tier' => 1, 'xp_threshold' => 40,
            'free_cosmetic_id' => $free->id, 'premium_cosmetic_id' => $premium->id,
        ]);

        $noPass = User::factory()->create();
        $this->finishSoloGame($noPass);
        $this->assertDatabaseHas('cosmetic_user', ['user_id' => $noPass->id, 'cosmetic_id' => $free->id]);
        $this->assertDatabaseMissing('cosmetic_user', ['user_id' => $noPass->id, 'cosmetic_id' => $premium->id]);

        // grantPass back-fills the premium reward for a tier already reached.
        app(SeasonService::class)->grantPass($noPass->id, $season);
        $this->assertDatabaseHas('cosmetic_user', [
            'user_id' => $noPass->id, 'cosmetic_id' => $premium->id, 'source' => 'pass',
        ]);

        // A user who already has the pass gets premium at unlock time.
        $withPass = User::factory()->create();
        SeasonProgress::create(['season_id' => $season->id, 'user_id' => $withPass->id, 'has_pass' => true]);
        $this->finishSoloGame($withPass);
        $this->assertDatabaseHas('cosmetic_user', [
            'user_id' => $withPass->id, 'cosmetic_id' => $premium->id, 'source' => 'pass',
        ]);
    }

    public function test_admin_can_grant_and_revoke_a_users_season_pass(): void
    {
        $season = $this->season();
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/season-pass", ['granted' => true])
            ->assertOk()->assertJsonPath('season_pass', true);
        $this->assertTrue((bool) SeasonProgress::where('user_id', $user->id)->value('has_pass'));

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/season-pass", ['granted' => false])
            ->assertOk()->assertJsonPath('season_pass', false);
    }
}
