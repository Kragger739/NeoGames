<?php

namespace Tests\Feature\Auth;

use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use App\Support\EmailVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /** A code that is guaranteed not to match the real one. */
    private function wrongCode(string $realCode): string
    {
        return str_pad((string) (((int) $realCode + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Issue a code for the user and recover its plaintext from the faked mail. */
    private function issueCode(User $user): string
    {
        EmailVerification::issue($user);

        // EmailVerificationCodeMail is ShouldQueue, so Mail::fake() records it
        // as queued rather than sent.
        $code = null;
        Mail::assertQueued(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) use ($user, &$code) {
            if ($mail->hasTo($user->email)) {
                $code = $mail->code;
            }

            return $code !== null;
        });

        return $code;
    }

    private function cacheKey(User $user): string
    {
        return "email-verification:{$user->id}";
    }

    public function test_registration_creates_an_unverified_user_and_emails_a_code(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Host Name',
            'email' => 'host@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accepted_terms' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('email_verified', false);

        $user = User::where('email', 'host@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertNotNull(Cache::get($this->cacheKey($user)));
        Mail::assertQueued(EmailVerificationCodeMail::class);
    }

    public function test_a_valid_code_verifies_the_email_and_consumes_the_code(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->issueCode($user);

        $response = $this->actingAs($user)->postJson('/api/email/verify', ['code' => $code]);

        $response->assertOk();
        $response->assertJsonPath('email_verified', true);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertNull(Cache::get($this->cacheKey($user)));
    }

    public function test_an_incorrect_code_is_rejected_and_burns_an_attempt(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->issueCode($user);

        $response = $this->actingAs($user)->postJson('/api/email/verify', [
            'code' => $this->wrongCode($code),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSame(1, Cache::get($this->cacheKey($user))['attempts']);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->issueCode($user);

        $this->travel(16)->minutes();

        $response = $this->actingAs($user)->postJson('/api/email/verify', ['code' => $code]);

        $response->assertUnprocessable();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $this->travelBack();
    }

    public function test_too_many_wrong_attempts_invalidates_the_code(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->issueCode($user);
        $wrong = $this->wrongCode($code);

        // Drive the attempt cap through the helper directly so the route's
        // throttle:6,1 doesn't swallow the final (correct-code) request.
        for ($i = 0; $i < 6; $i++) {
            $this->assertFalse(EmailVerification::verify($user, $wrong));
        }

        $this->assertNull(Cache::get($this->cacheKey($user)));

        $response = $this->actingAs($user)->postJson('/api/email/verify', ['code' => $code]);
        $response->assertUnprocessable();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resending_a_code_is_rate_limited_by_a_cooldown(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson('/api/email/verification-code')
            ->assertOk()
            ->assertJsonPath('sent', true);

        $blocked = $this->actingAs($user)->postJson('/api/email/verification-code');
        $blocked->assertStatus(429);
        $this->assertIsInt($blocked->json('retry_after'));

        $this->travel(61)->seconds();

        $this->actingAs($user)->postJson('/api/email/verification-code')->assertOk();

        $this->travelBack();

        Mail::assertQueued(EmailVerificationCodeMail::class, 2);
    }

    public function test_resending_is_a_noop_for_an_already_verified_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/email/verification-code')->assertOk();

        Mail::assertNothingOutgoing();
    }

    public function test_verify_rejects_a_non_numeric_or_wrong_length_code(): void
    {
        $user = User::factory()->unverified()->create();
        $this->issueCode($user);

        $this->actingAs($user)->postJson('/api/email/verify', ['code' => 'abcdef'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($user)->postJson('/api/email/verify', ['code' => '123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_an_unverified_user_is_locked_out_of_the_app(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->getJson('/api/friends')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/rooms')->assertStatus(403);

        // ...but can still reach the escape hatches.
        $this->actingAs($user)->getJson('/api/user')->assertOk();
        $this->actingAs($user)->postJson('/api/logout')->assertNoContent();
    }

    public function test_a_verified_user_can_use_the_app(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/friends')->assertOk();
    }
}
