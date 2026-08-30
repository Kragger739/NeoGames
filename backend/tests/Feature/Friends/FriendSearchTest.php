<?php

namespace Tests\Feature\Friends;

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FriendSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_users_by_username_substring(): void
    {
        $me = User::factory()->create(['username' => 'searcher']);
        User::factory()->create(['username' => 'alice']);
        User::factory()->create(['username' => 'malick']);
        User::factory()->create(['username' => 'bob']);

        $response = $this->actingAs($me)->getJson('/api/friends/search?q=ali');

        $response->assertOk();
        $response->assertJsonCount(2, 'results');
        $usernames = collect($response->json('results'))->pluck('username')->all();
        $this->assertEqualsCanonicalizing(['alice', 'malick'], $usernames);
    }

    public function test_it_excludes_the_searcher(): void
    {
        $me = User::factory()->create(['username' => 'alison']);

        $response = $this->actingAs($me)->getJson('/api/friends/search?q=ali');

        $response->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_it_excludes_existing_friends(): void
    {
        $me = User::factory()->create(['username' => 'searcher']);
        $friend = User::factory()->create(['username' => 'alice']);
        User::factory()->create(['username' => 'alicia']);
        Friendship::create(['user_id' => $me->id, 'friend_id' => $friend->id, 'status' => 'accepted']);

        $response = $this->actingAs($me)->getJson('/api/friends/search?q=ali');

        $response->assertOk()->assertJsonCount(1, 'results');
        $this->assertSame('alicia', $response->json('results.0.username'));
    }

    public function test_it_excludes_pending_requests_in_either_direction(): void
    {
        $me = User::factory()->create(['username' => 'searcher']);
        $outgoing = User::factory()->create(['username' => 'alice']);
        $incoming = User::factory()->create(['username' => 'alicia']);
        Friendship::create(['user_id' => $me->id, 'friend_id' => $outgoing->id, 'status' => 'pending']);
        Friendship::create(['user_id' => $incoming->id, 'friend_id' => $me->id, 'status' => 'pending']);

        $response = $this->actingAs($me)->getJson('/api/friends/search?q=ali');

        $response->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_it_ignores_users_without_a_username(): void
    {
        $me = User::factory()->create(['username' => 'searcher']);
        User::factory()->create(['username' => null, 'name' => 'Alicia Keys']);

        $response = $this->actingAs($me)->getJson('/api/friends/search?q=ali');

        $response->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_it_caps_results_at_eight(): void
    {
        $me = User::factory()->create(['username' => 'searcher']);
        for ($i = 0; $i < 12; $i++) {
            User::factory()->create(['username' => "matcher{$i}"]);
        }

        $response = $this->actingAs($me)->getJson('/api/friends/search?q=matcher');

        $response->assertOk()->assertJsonCount(8, 'results');
    }

    public function test_a_short_query_is_rejected(): void
    {
        $me = User::factory()->create(['username' => 'searcher']);

        $this->actingAs($me)->getJson('/api/friends/search?q=a')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/friends/search?q=ali')->assertUnauthorized();
    }

    public function test_it_requires_a_verified_email(): void
    {
        $me = User::factory()->unverified()->create(['username' => 'searcher']);

        $this->actingAs($me)->getJson('/api/friends/search?q=ali')->assertStatus(403);
    }

    public function test_result_shape(): void
    {
        $me = User::factory()->create(['username' => 'searcher']);
        User::factory()->create(['username' => 'alice']);

        $this->actingAs($me)->getJson('/api/friends/search?q=ali')
            ->assertOk()
            ->assertJsonStructure([
                'results' => [
                    ['id', 'username', 'level', 'xp', 'avatar'],
                ],
            ]);
    }
}
