<?php

namespace Tests\Feature\Admin;

use App\Models\DailyChallenge;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDailyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    public function test_show_returns_todays_five_songs(): void
    {
        Song::factory()->count(12)->create(['genre' => 'iconic']);

        $res = $this->actingAs($this->admin())->getJson('/api/admin/daily')->assertOk();

        $this->assertCount(5, $res->json('songs'));
        $this->assertFalse($res->json('curated'));
        $this->assertFalse($res->json('has_attempts'));
    }

    public function test_an_admin_can_override_the_days_songs(): void
    {
        Song::factory()->count(12)->create(['genre' => 'iconic']);
        $picks = Song::factory()->count(5)->create()->pluck('id')->all();
        $today = now()->toDateString();

        $this->actingAs($this->admin())
            ->patchJson("/api/admin/daily/{$today}", ['song_ids' => $picks])
            ->assertOk()
            ->assertJsonPath('curated', true);

        $challenge = DailyChallenge::whereDate('date', $today)->first();
        $this->assertSame($picks, $challenge->song_ids);
    }

    public function test_it_rejects_a_wrong_count_or_unknown_id_or_duplicates(): void
    {
        Song::factory()->count(12)->create(['genre' => 'iconic']);
        $ids = Song::query()->limit(5)->pluck('id')->all();
        $today = now()->toDateString();
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/admin/daily/{$today}", ['song_ids' => array_slice($ids, 0, 4)])
            ->assertUnprocessable();

        $this->actingAs($admin)->patchJson("/api/admin/daily/{$today}", ['song_ids' => [...array_slice($ids, 0, 4), 999999]])
            ->assertUnprocessable();

        $this->actingAs($admin)->patchJson("/api/admin/daily/{$today}", ['song_ids' => [$ids[0], $ids[0], $ids[1], $ids[2], $ids[3]]])
            ->assertUnprocessable();
    }

    public function test_it_rejects_a_past_date_and_non_admins(): void
    {
        Song::factory()->count(12)->create(['genre' => 'iconic']);
        $ids = Song::query()->limit(5)->pluck('id')->all();
        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $this->actingAs($this->admin())->patchJson("/api/admin/daily/{$yesterday}", ['song_ids' => $ids])
            ->assertUnprocessable();

        $this->actingAs(User::factory()->create())->patchJson("/api/admin/daily/{$today}", ['song_ids' => $ids])
            ->assertForbidden();
    }
}
