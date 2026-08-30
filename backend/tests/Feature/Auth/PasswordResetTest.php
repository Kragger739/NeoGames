<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_a_reset_link_is_emailed_to_a_registered_user(): void
    {
        $user = User::factory()->create(['email' => 'host@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'host@example.com'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_address_gets_the_same_response_and_no_email(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', "If that address has an account, we've emailed a reset link.");

        Notification::assertNothingSent();
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create([
            'email' => 'host@example.com',
            'password' => Hash::make('old-password-123'),
        ]);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'host@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));

        $this->postJson('/api/login', [
            'email' => 'host@example.com',
            'password' => 'brand-new-password',
        ])->assertOk();
    }

    public function test_a_bad_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'host@example.com']);

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'host@example.com',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_forgot_password_is_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/forgot-password', ['email' => "user{$i}@example.com"]);
        }

        $this->postJson('/api/forgot-password', ['email' => 'user7@example.com'])
            ->assertStatus(429);
    }
}
