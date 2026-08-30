<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSocialiteUser(string $id, string $email, string $name = 'Test Player'): SocialiteUser
    {
        return (new SocialiteUser)->setRaw([])->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'avatar' => null,
        ]);
    }

    public function test_a_new_email_via_google_creates_and_logs_in_a_user(): void
    {
        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser('google-123', 'newplayer@example.com', 'New Player'));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('http://localhost:5173/');

        $this->assertDatabaseHas('users', [
            'email' => 'newplayer@example.com',
            'provider' => 'google',
            'provider_id' => 'google-123',
        ]);

        $user = User::where('email', 'newplayer@example.com')->first();
        $this->assertAuthenticatedAs($user);
        // The provider already verified the address - no code screen for OAuth.
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_signing_in_with_a_provider_verifies_a_previously_unverified_account(): void
    {
        $existing = User::factory()->unverified()->create(['email' => 'pending@example.com']);
        $this->assertFalse($existing->hasVerifiedEmail());

        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser('google-321', 'pending@example.com'));

        $this->get('/api/auth/google/callback')->assertRedirect('http://localhost:5173/');

        $this->assertTrue($existing->fresh()->hasVerifiedEmail());
    }

    public function test_an_existing_email_logs_into_that_account_instead_of_duplicating_it(): void
    {
        $existing = User::factory()->create(['email' => 'already@example.com']);

        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser('google-456', 'already@example.com'));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('http://localhost:5173/');
        $this->assertSame(1, User::where('email', 'already@example.com')->count());
        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'provider' => 'google',
            'provider_id' => 'google-456',
        ]);
    }

    public function test_an_existing_email_logs_in_via_discord_too(): void
    {
        $existing = User::factory()->create(['email' => 'already@example.com']);

        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser('discord-789', 'already@example.com'));

        $response = $this->get('/api/auth/discord/callback');

        $response->assertRedirect('http://localhost:5173/');
        $this->assertSame(1, User::where('email', 'already@example.com')->count());
        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'provider' => 'discord',
            'provider_id' => 'discord-789',
        ]);
    }

    public function test_a_provider_failure_redirects_to_login_with_an_error_and_creates_no_user(): void
    {
        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andThrow(new \Exception('user denied consent'));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect('http://localhost:5173/login?error=oauth_failed');
        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    /**
     * The actual bug this controller works around: a browser starting the
     * flow from a second configured origin (e.g. a Cloudflare tunnel used
     * to test from another device) must land back on that SAME origin, not
     * the default - landing anywhere else would mean the session cookie set
     * during /redirect never reaches /callback, since cookies don't cross
     * origins. Config::set (not putenv) because the controller reads the
     * already-parsed list off config/cors.php.
     */
    public function test_the_callback_returns_to_the_origin_the_redirect_came_from(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173', 'https://tunnel.example.com']]);

        $redirectResponse = $this->withHeaders(['referer' => 'https://tunnel.example.com/login'])
            ->get('/api/auth/google/redirect');

        // Socialite::driver() isn't faked here - a real driver builds the
        // authorize URL, which is enough to prove redirectUrl() received
        // the tunnel origin's callback URL, not the default's.
        $redirectResponse->assertRedirectContains(
            'redirect_uri='.urlencode('https://tunnel.example.com/api/auth/google/callback')
        );

        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser('google-999', 'tunnel-user@example.com'));

        // No Referer this time - a real provider redirect back to our
        // callback wouldn't carry one; only the session (set during the
        // /redirect request above, and still attached to this same test
        // client) should determine where the user lands.
        $callbackResponse = $this->get('/api/auth/google/callback');

        $callbackResponse->assertRedirect('https://tunnel.example.com/');
    }

    /**
     * The Referer header isn't guaranteed (browsers/extensions/privacy
     * settings can strip or alter it) - the frontend sends the origin
     * explicitly instead, and that must be honoured even with no Referer
     * at all.
     */
    public function test_the_explicit_origin_param_works_without_a_referer(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173', 'https://tunnel.example.com']]);

        $redirectResponse = $this->get('/api/auth/google/redirect?origin=https%3A%2F%2Ftunnel.example.com');

        $redirectResponse->assertRedirectContains(
            'redirect_uri='.urlencode('https://tunnel.example.com/api/auth/google/callback')
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
