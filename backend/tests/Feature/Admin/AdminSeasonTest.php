<?php

namespace Tests\Feature\Admin;

use App\Models\Cosmetic;
use App\Models\Season;
use App\Models\SeasonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSeasonTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    private function season(string $slug = 'existing', array $attrs = []): Season
    {
        return Season::create(array_merge([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
        ], $attrs));
    }

    public function test_non_admins_are_rejected(): void
    {
        $this->getJson('/api/admin/seasons')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/seasons')->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/api/admin/seasons', ['name' => 'X'])->assertForbidden();
    }

    public function test_create_from_a_length_computes_the_end_date(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/admin/seasons', [
            'name' => 'Winter Blast',
            'starts_at' => '2026-12-01',
            'length_days' => 30,
        ])->assertCreated();

        $this->assertSame('winter-blast', $res->json('slug'));
        $season = Season::find($res->json('id'));
        $this->assertSame('2026-12-01', $season->starts_at->toDateString());
        $this->assertSame('2026-12-31', $season->ends_at->toDateString());
    }

    public function test_create_with_an_explicit_end_date(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/seasons', [
            'name' => 'S',
            'starts_at' => '2026-12-01',
            'ends_at' => '2027-01-15',
        ])->assertCreated()->assertJsonPath('ends_at', fn ($v) => str_starts_with($v, '2027-01-15'));
    }

    public function test_slugs_are_made_unique(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/admin/seasons', ['name' => 'Same', 'length_days' => 10])
            ->assertJsonPath('slug', 'same');
        $this->actingAs($admin)->postJson('/api/admin/seasons', ['name' => 'Same', 'length_days' => 10])
            ->assertJsonPath('slug', 'same-2');
    }

    public function test_a_future_start_does_not_become_current(): void
    {
        $current = $this->season('now');

        $this->actingAs($this->admin())->postJson('/api/admin/seasons', [
            'name' => 'Later',
            'starts_at' => now()->addDays(40)->toDateString(),
            'length_days' => 30,
        ])->assertCreated();

        $this->assertSame($current->id, Season::current()->id);
    }

    public function test_clone_from_copies_the_tier_ladder(): void
    {
        $src = $this->season('src');
        $c = Cosmetic::create(['slot' => 'frame', 'key' => 'k1', 'name' => 'K1', 'rarity' => 'common', 'source' => 'track']);
        $src->tiers()->create(['tier' => 1, 'xp_threshold' => 100, 'free_cosmetic_id' => $c->id]);
        $src->tiers()->create(['tier' => 2, 'xp_threshold' => 250, 'free_cosmetic_id' => null]);

        $res = $this->actingAs($this->admin())->postJson('/api/admin/seasons', [
            'name' => 'Clone', 'length_days' => 30, 'clone_from' => $src->id,
        ])->assertCreated();

        $clone = Season::find($res->json('id'));
        $this->assertSame(2, $clone->tiers()->count());
        $this->assertSame(100, $clone->tiers()->where('tier', 1)->value('xp_threshold'));
        $this->assertSame($c->id, $clone->tiers()->where('tier', 1)->value('free_cosmetic_id'));
    }

    public function test_update_changes_the_dates(): void
    {
        $season = $this->season();

        $this->actingAs($this->admin())->patchJson("/api/admin/seasons/{$season->id}", [
            'name' => 'Renamed',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-02-01',
        ])->assertOk()->assertJsonPath('name', 'Renamed');

        $this->assertSame('2026-02-01', $season->fresh()->ends_at->toDateString());
    }

    public function test_update_rejects_an_end_before_the_start(): void
    {
        $season = $this->season();

        $this->actingAs($this->admin())->patchJson("/api/admin/seasons/{$season->id}", [
            'name' => 'X', 'starts_at' => '2026-05-01', 'ends_at' => '2026-04-01',
        ])->assertUnprocessable();
    }

    public function test_delete_cascades_progress_and_tiers(): void
    {
        $this->season('keep');
        $doomed = $this->season('doomed');
        $doomed->tiers()->create(['tier' => 1, 'xp_threshold' => 100]);
        SeasonProgress::create(['season_id' => $doomed->id, 'user_id' => User::factory()->create()->id, 'xp' => 10]);

        $this->actingAs($this->admin())->deleteJson("/api/admin/seasons/{$doomed->id}")->assertNoContent();

        $this->assertDatabaseMissing('seasons', ['id' => $doomed->id]);
        $this->assertDatabaseMissing('season_tiers', ['season_id' => $doomed->id]);
        $this->assertDatabaseMissing('season_progress', ['season_id' => $doomed->id]);
    }

    public function test_the_only_season_cannot_be_deleted(): void
    {
        $only = $this->season();

        $this->actingAs($this->admin())->deleteJson("/api/admin/seasons/{$only->id}")->assertUnprocessable();
    }
}
