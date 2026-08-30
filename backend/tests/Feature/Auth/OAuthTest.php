<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSocialiteUser(string $id, string $email, string $name = 'Test Player', ?string $avatar = null): SocialiteUser
    {
        return (new SocialiteUser)->setRaw([])->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'avatar' => $avatar,
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

    public function test_a_new_oauth_user_gets_the_provider_avatar_saved_locally(): void
    {
        Storage::fake('public');
        Http::fake([
            'cdn.example.com/*' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser(
                'google-av-1', 'withavatar@example.com', 'Av Player', 'https://cdn.example.com/a/1.png'
            ));

        $this->get('/api/auth/google/callback')->assertRedirect('http://localhost:5173/');

        $user = User::where('email', 'withavatar@example.com')->first();
        $this->assertNotNull($user->avatar_path);
        $this->assertStringStartsWith('avatars/', $user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_a_provider_with_no_avatar_leaves_the_account_on_the_default(): void
    {
        Storage::fake('public');

        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser('google-av-2', 'noavatar@example.com'));

        $this->get('/api/auth/google/callback')->assertRedirect('http://localhost:5173/');

        $this->assertNull(User::where('email', 'noavatar@example.com')->first()->avatar_path);
    }

    public function test_a_failed_or_non_image_avatar_download_does_not_block_signup(): void
    {
        Storage::fake('public');
        Http::fake([
            'cdn.example.com/*' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser(
                'google-av-3', 'badavatar@example.com', 'Bad Av', 'https://cdn.example.com/a/3.png'
            ));

        $this->get('/api/auth/google/callback')->assertRedirect('http://localhost:5173/');

        $user = User::where('email', 'badavatar@example.com')->first();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->avatar_path);
    }

    public function test_an_existing_account_linking_a_provider_keeps_its_current_picture(): void
    {
        Storage::fake('public');
        Http::fake([
            'cdn.example.com/*' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);
        $existing = User::factory()->create(['email' => 'haspic@example.com', 'avatar_path' => 'avatars/mine.png']);

        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser(
                'google-av-4', 'haspic@example.com', 'Has Pic', 'https://cdn.example.com/a/4.png'
            ));

        $this->get('/api/auth/google/callback')->assertRedirect('http://localhost:5173/');

        $this->assertSame('avatars/mine.png', $existing->fresh()->avatar_path);
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

    public function test_a_banned_user_is_redirected_to_login_and_not_authenticated(): void
    {
        $banned = User::factory()->create(['email' => 'banned@example.com']);
        $banned->forceFill(['banned_at' => now(), 'ban_reason' => 'Cheating'])->save();

        Socialite::shouldReceive('driver->redirectUrl->user')
            ->once()
            ->andReturn($this->fakeSocialiteUser('google-777', 'banned@example.com'));

        $this->get('/api/auth/google/callback')
            ->assertRedirect('http://localhost:5173/login?error=banned');

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
