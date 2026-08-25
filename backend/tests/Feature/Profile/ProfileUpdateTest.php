<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_can_change_their_username(): void
    {
        $host = User::factory()->create(['username' => 'oldname']);

        $response = $this->actingAs($host)->patchJson('/api/profile', ['username' => 'newname']);

        $response->assertOk();
        $response->assertJsonPath('username', 'newname');
        $this->assertDatabaseHas('users', ['id' => $host->id, 'username' => 'newname']);
    }

    public function test_username_must_be_unique(): void
    {
        User::factory()->create(['username' => 'taken']);
        $host = User::factory()->create(['username' => 'mine']);

        $response = $this->actingAs($host)->patchJson('/api/profile', ['username' => 'taken']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('username');
    }

    public function test_a_host_can_keep_their_own_current_username(): void
    {
        $host = User::factory()->create(['username' => 'mine']);

        $response = $this->actingAs($host)->patchJson('/api/profile', ['username' => 'mine']);

        $response->assertOk();
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->patchJson('/api/profile', ['username' => 'nope'])->assertUnauthorized();
    }
}
