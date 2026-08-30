<?php

namespace Tests\Feature\Leaderboard;

use App\Models\Season;
use App\Models\SeasonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function season(): Season
    {
        return Season::create([
            'name' => 'Season 1', 'slug' => 's1',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(30),
        ]);
    }

    private function progress(Season $s, User $u, int $xp): void
    {
        SeasonProgress::create(['season_id' => $s->id, 'user_id' => $u->id, 'xp' => $xp]);
    }

    public function test_ranks_players_by_season_xp_descending(): void
    {
        $season = $this->season();
        $alpha = User::factory()->create(['username' => 'alpha']);
        $bravo = User::factory()->create(['username' => 'bravo']);
        $charlie = User::factory()->create(['username' => 'charlie']);
        $this->progress($season, $alpha, 300);
        $this->progress($season, $bravo, 500);
        $this->progress($season, $charlie, 100);

        $response = $this->actingAs($charlie)->getJson('/api/leaderboard');

        $response->assertOk();
        $response->assertJsonPath('entries.0.username', 'bravo');
        $response->assertJsonPath('entries.0.rank', 1);
        $response->assertJsonPath('entries.1.username', 'alpha');
        $response->assertJsonPath('entries.2.username', 'charlie');
        $response->assertJsonPath('me.rank', 3);
        $response->assertJsonPath('me.season_xp', 100);
        $this->assertNotNull($response->json('entries.0.avatar'));
        $this->assertArrayHasKey('cosmetics', $response->json('entries.0.avatar'));
    }

    public function test_top_n_is_capped_and_a_viewer_without_progress_has_no_me(): void
    {
        config(['seasons.leaderboard_top_n' => 2]);
        $season = $this->season();
        foreach (range(1, 5) as $i) {
            $this->progress($season, User::factory()->create(), $i * 100);
        }

        $response = $this->actingAs(User::factory()->create())->getJson('/api/leaderboard');

        $this->assertCount(2, $response->json('entries'));
        $this->assertNull($response->json('me'));
    }

    public function test_empty_payload_when_no_active_season(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/leaderboard')
            ->assertOk()
            ->assertJson(['season' => null, 'entries' => [], 'me' => null]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/leaderboard')->assertUnauthorized();
    }
}
