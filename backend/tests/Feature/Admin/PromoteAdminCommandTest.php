<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoteAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_promotes_a_user_by_email(): void
    {
        $user = User::factory()->create(['email' => 'promote@example.com']);
        $this->assertFalse((bool) $user->is_admin);

        $this->artisan('admin:promote', ['email' => 'promote@example.com'])
            ->assertExitCode(0);

        $this->assertTrue((bool) $user->fresh()->is_admin);
    }

    public function test_it_fails_cleanly_for_an_unknown_email(): void
    {
        $this->artisan('admin:promote', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('No user with that email')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }
}
