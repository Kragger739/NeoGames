<?php

namespace Tests\Feature\Admin;

use App\Models\Season;
use App\Models\SeasonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // is_admin is deliberately NOT fillable (Task 1) - force it on.
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    public function test_non_admins_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_list_users(): void
    {
        $admin = $this->admin();
        User::factory()->count(3)->create();

        $this->actingAs($admin)->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonStructure([
                'data' => [['id', 'name', 'username', 'email', 'email_verified', 'is_admin', 'banned_at', 'avatar']],
                'meta' => ['current_page', 'last_page', 'total'],
            ]);
    }

    public function test_list_paginates_at_25_per_page(): void
    {
        $admin = $this->admin();
        User::factory()->count(25)->create(); // 26 total with the admin

        $first = $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();
        $this->assertCount(25, $first->json('data'));
        $first->assertJsonPath('meta.last_page', 2);

        $second = $this->actingAs($admin)->getJson('/api/admin/users?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Same-second created_at rows are common in tests; the id tiebreak
        // must give a stable full walk with no dupes and no gaps.
        $ids = collect($first->json('data'))->merge($second->json('data'))->pluck('id');
        $this->assertCount(26, $ids->unique());
    }

    public function test_list_search_matches_name_username_or_email(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Zebra Person', 'username' => 'zeb', 'email' => 'zeb@example.com']);
        User::factory()->create(['name' => 'Other', 'username' => 'other', 'email' => 'other@example.com']);

        $res = $this->actingAs($admin)->getJson('/api/admin/users?search=zebra')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('zeb', $res->json('data.0.username'));

        $res2 = $this->actingAs($admin)->getJson('/api/admin/users?search=other@example')->assertOk();
        $this->assertCount(1, $res2->json('data'));
    }

    public function test_admin_can_view_a_single_user(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['name' => 'Target', 'xp' => 300]);

        $this->actingAs($admin)->getJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('id', $target->id)
            ->assertJsonPath('name', 'Target')
            ->assertJsonPath('level', 3)
            ->assertJsonPath('is_admin', false);
    }

    public function test_show_404s_for_an_unknown_user(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/users/999999')
            ->assertNotFound();
    }

    public function test_non_admin_cannot_view_a_user(): void
    {
        $target = User::factory()->create();
        $this->actingAs(User::factory()->create())
            ->getJson("/api/admin/users/{$target->id}")
            ->assertForbidden();
    }

    public function test_admin_can_update_core_fields(): void
    {
        $admin = $this->admin();
        $target = User::factory()->unverified()->create(['name' => 'Old', 'username' => 'old', 'email' => 'old@example.com']);

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", [
            'name' => 'New Name',
            'username' => 'newname',
            'email' => 'new@example.com',
            'email_verified' => true,
            'is_admin' => true,
        ])->assertOk()
            ->assertJsonPath('name', 'New Name')
            ->assertJsonPath('username', 'newname')
            ->assertJsonPath('email_verified', true)
            ->assertJsonPath('is_admin', true);

        $target->refresh();
        $this->assertNotNull($target->email_verified_at);
        $this->assertTrue($target->is_admin);
    }

    public function test_update_can_unverify_an_email(): void
    {
        $admin = $this->admin();
        // Explicit alpha_dash-safe username/email: the request re-submits the
        // target's current values unchanged, so faker's default userName()
        // (which is ~50% dot-containing) would otherwise fail `alpha_dash`.
        $target = User::factory()->create([
            'username' => 'verifieduser',
            'email' => 'verified@example.com',
        ]); // verified by factory default

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", [
            'name' => $target->name,
            'username' => $target->username,
            'email' => $target->email,
            'email_verified' => false,
            'is_admin' => false,
        ])->assertOk()->assertJsonPath('email_verified', false);

        $this->assertNull($target->refresh()->email_verified_at);
    }

    public function test_update_rejects_a_taken_username_but_allows_the_users_own(): void
    {
        $admin = $this->admin();
        User::factory()->create(['username' => 'taken']);
        $target = User::factory()->create(['username' => 'mine']);

        $base = fn (array $over) => array_merge([
            'name' => $target->name, 'username' => 'mine', 'email' => $target->email,
            'email_verified' => true, 'is_admin' => false,
        ], $over);

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", $base(['username' => 'taken']))
            ->assertUnprocessable()->assertJsonValidationErrors('username');

        $this->actingAs($admin)->patchJson("/api/admin/users/{$target->id}", $base([]))
            ->assertOk();
    }

    public function test_admin_cannot_remove_their_own_admin_access(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}", [
            'name' => $admin->name,
            'username' => $admin->username,
            'email' => $admin->email,
            'email_verified' => true,
            'is_admin' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_admin');

        $this->assertTrue($admin->refresh()->is_admin);
    }

    public function test_admin_cannot_unverify_their_own_email(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}", [
            'name' => $admin->name,
            'username' => $admin->username,
            'email' => $admin->email,
            'email_verified' => false,
            'is_admin' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('email_verified');

        $this->assertNotNull($admin->refresh()->email_verified_at);
    }

    public function test_admin_can_delete_a_user(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_delete_removes_the_users_avatar_file(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $target = User::factory()->create();
        $path = 'avatars/pic.png';
        Storage::disk('public')->put($path, 'x');
        $target->update(['avatar_path' => $path]);

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$target->id}")
            ->assertNoContent();

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_cannot_delete_their_own_account_via_admin_api(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_can_ban_and_unban_a_user(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        \DB::table('sessions')->insert([
            'id' => 'sess-1', 'user_id' => $target->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time(),
        ]);

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/ban", ['reason' => 'Spam'])
            ->assertOk()
            ->assertJsonPath('ban_reason', 'Spam')
            ->assertJson(fn ($json) => $json->where('banned_at', fn ($v) => $v !== null)->etc());

        $this->assertNotNull($target->refresh()->banned_at);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/unban")
            ->assertOk()
            ->assertJsonPath('banned_at', null)
            ->assertJsonPath('ban_reason', null);

        $this->assertNull($target->refresh()->banned_at);
    }

    public function test_ban_nulls_the_remember_token(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $target->forceFill(['remember_token' => 'abc123def456ghi789'])->save();

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/ban")
            ->assertOk();

        $this->assertNull($target->fresh()->remember_token);
    }

    public function test_ban_reason_is_optional(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/ban")
            ->assertOk()
            ->assertJsonPath('ban_reason', null);
    }

    public function test_admin_cannot_ban_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/admin/users/{$admin->id}/ban")
            ->assertStatus(422);

        $this->assertNull($admin->refresh()->banned_at);
    }

    public function test_admin_can_reset_a_users_xp(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['xp' => 5000]);
        $season = Season::create([
            'name' => 'Test Season',
            'slug' => 'test-season',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
        SeasonProgress::create(['season_id' => $season->id, 'user_id' => $target->id, 'xp' => 4200]);

        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/reset-xp")
            ->assertOk()
            ->assertJsonPath('xp', 0);

        $this->assertSame(0, (int) $target->refresh()->xp);
        $this->assertDatabaseMissing('season_progress', ['user_id' => $target->id]);
    }
}
