<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Registration now emails a verification code - keep it off the wire.
        Mail::fake();
    }

    public function test_a_host_can_register(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Host Name',
            'email' => 'host@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accepted_terms' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('email', 'host@example.com');
        $response->assertJsonPath('username', 'hostname');
        $this->assertDatabaseHas('users', ['email' => 'host@example.com', 'username' => 'hostname']);
    }

    public function test_registration_auto_suffixes_the_username_on_collision(): void
    {
        User::factory()->create(['username' => 'hostname']);

        $response = $this->postJson('/api/register', [
            'name' => 'Host Name',
            'email' => 'second-host@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accepted_terms' => true,
        ]);

        $response->assertCreated();
        $this->assertNotSame('hostname', $response->json('username'));
        $this->assertStringStartsWith('hostname', $response->json('username'));
    }

    public function test_registration_requires_a_unique_email(): void
    {
        User::factory()->create(['email' => 'host@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Another Host',
            'email' => 'host@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accepted_terms' => true,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Host Name',
            'email' => 'host@example.com',
            'password' => 'password123',
            'password_confirmation' => 'not-matching',
            'accepted_terms' => true,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('password');
    }

    public function test_registration_requires_accepting_the_terms(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Host Name',
            'email' => 'host@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accepted_terms' => false,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('accepted_terms');
    }
}
