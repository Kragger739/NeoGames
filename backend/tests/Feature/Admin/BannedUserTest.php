<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BannedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_banned_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'ban@example.com',
            'password' => Hash::make('secret123'),
        ])->forceFill([
            'banned_at' => now(),
            'ban_reason' => 'Cheating',
        ])->save();

        $this->postJson('/api/login', ['email' => 'ban@example.com', 'password' => 'secret123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_a_banned_users_live_session_is_rejected_on_the_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/user')->assertOk();

        $user->forceFill(['banned_at' => now(), 'ban_reason' => 'Spam'])->save();

        $this->actingAs($user)->getJson('/api/user')
            ->assertForbidden()
            ->assertJsonPath('reason', 'Spam');
    }

    public function test_a_banned_user_can_still_log_out(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['banned_at' => now()])->save();

        $this->actingAs($user)->postJson('/api/logout')->assertNoContent();
    }

    public function test_a_banned_user_can_still_delete_their_own_account(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $user->forceFill(['banned_at' => now(), 'ban_reason' => 'Cheating'])->save();

        $this->actingAs($user)->deleteJson('/api/user', ['password' => 'secret123'])
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
