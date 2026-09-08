<?php

namespace Tests\Feature\Admin;

use App\Models\NeoCoinEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNeoCoinsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    public function test_an_admin_can_grant_neo_coins(): void
    {
        $user = User::factory()->create(['neo_coins' => 100]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/users/{$user->id}/neo-coins", ['delta' => 250])
            ->assertOk()
            ->assertJsonPath('neo_coins', 350);

        $this->assertDatabaseHas('neo_coin_events', [
            'user_id' => $user->id, 'amount' => 250, 'reason' => 'admin_grant',
        ]);
    }

    public function test_a_deduction_is_clamped_at_zero(): void
    {
        $user = User::factory()->create(['neo_coins' => 100]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/users/{$user->id}/neo-coins", ['delta' => -500])
            ->assertOk()
            ->assertJsonPath('neo_coins', 0);

        // The ledger records the delta actually applied (-100), not -500.
        $this->assertDatabaseHas('neo_coin_events', [
            'user_id' => $user->id, 'amount' => -100, 'reason' => 'admin_grant',
        ]);
    }

    public function test_reset_xp_also_zeroes_coins_and_purges_the_ledger(): void
    {
        $user = User::factory()->create(['xp' => 500, 'neo_coins' => 400]);
        NeoCoinEvent::create(['user_id' => $user->id, 'amount' => 400, 'reason' => 'level_up', 'dedup_key' => "level_up:{$user->id}:2"]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/users/{$user->id}/reset-xp")
            ->assertOk()
            ->assertJsonPath('xp', 0)
            ->assertJsonPath('neo_coins', 0);

        $this->assertSame(0, NeoCoinEvent::where('user_id', $user->id)->count());
    }

    public function test_a_non_admin_cannot_grant_coins(): void
    {
        $user = User::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/admin/users/{$user->id}/neo-coins", ['delta' => 100])
            ->assertForbidden();
    }
}
