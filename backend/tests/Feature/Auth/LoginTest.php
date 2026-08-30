<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_can_login_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'host@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'host@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $this->assertAuthenticated();
    }

    public function test_login_fails_with_incorrect_password(): void
    {
        User::factory()->create([
            'email' => 'host@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'host@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'target@example.com',
            'password' => Hash::make('password123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'target@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/api/login', [
            'email' => 'target@example.com',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
        $this->assertStringContainsString('Too many login attempts', $response->json('errors.email.0'));
        $this->assertGuest();
    }

    public function test_authenticated_host_can_fetch_own_user_and_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/user');
        $response->assertOk();
        $response->assertJsonPath('id', $user->id);

        $logout = $this->actingAs($user)->postJson('/api/logout');
        $logout->assertNoContent();
    }

    public function test_ping_requires_authentication(): void
    {
        $this->getJson('/api/ping')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/ping')->assertOk()->assertJson([
            'pong' => true,
            'authenticated' => true,
        ]);
    }
}
