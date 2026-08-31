<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUnlockTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    public function test_non_admins_cannot_read_or_write_requirements(): void
    {
        $this->getJson('/api/admin/unlock-requirements')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/unlock-requirements')->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->patchJson('/api/admin/unlock-requirements/game_night', ['required_level' => 3])
            ->assertForbidden();
    }

    public function test_index_lists_every_gate_key_with_its_level(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/admin/unlock-requirements')->assertOk();

        $keys = collect($res->json('requirements'))->pluck('key');
        $this->assertContains('game_night', $keys);
        $this->assertContains('mode:battle_royale', $keys);
        $this->assertContains('genre:pop', $keys);

        $br = collect($res->json('requirements'))->firstWhere('key', 'mode:battle_royale');
        $this->assertSame(3, $br['required_level']);
    }

    public function test_updating_a_requirement_takes_effect_immediately(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson('/api/admin/unlock-requirements/genre:pop', ['required_level' => 7])
            ->assertOk()
            ->assertJsonPath('required_level', 7);

        // The public map (used by the client + the validation rule) reflects it.
        $this->actingAs(User::factory()->create(['xp' => 1_000_000]))
            ->getJson('/api/unlock-requirements')
            ->assertOk()
            ->assertJsonPath('genre:pop', 7);
    }

    public function test_it_rejects_unknown_keys_and_out_of_range_levels(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson('/api/admin/unlock-requirements/genre:banana', ['required_level' => 2])
            ->assertNotFound();

        $this->actingAs($admin)
            ->patchJson('/api/admin/unlock-requirements/game_night', ['required_level' => 0])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->patchJson('/api/admin/unlock-requirements/game_night', ['required_level' => 99])
            ->assertUnprocessable();
    }
}
