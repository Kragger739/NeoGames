<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($admin)->getJson('/api/admin/users?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data');
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
}
