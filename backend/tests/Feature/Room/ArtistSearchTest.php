<?php

namespace Tests\Feature\Room;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArtistSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_can_search_for_artists(): void
    {
        $this->fakeSpotifyToken();
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response([
                'artists' => ['items' => [
                    ['id' => 'sp-1', 'name' => 'Small Following', 'images' => [['url' => 'https://example.com/a.jpg']], 'followers' => ['total' => 100]],
                    ['id' => 'sp-2', 'name' => 'Real Artist', 'images' => [['url' => 'https://example.com/b.jpg']], 'followers' => ['total' => 5_000_000]],
                ]],
            ], 200),
        ]);

        $host = User::factory()->create();

        $response = $this->actingAs($host)->getJson('/api/artists/search?q=artist');

        $response->assertOk();
        $response->assertJsonCount(2, 'results');
        // Sorted by follower count, not Spotify's own result order.
        $response->assertJsonPath('results.0.name', 'Real Artist');
        $response->assertJsonPath('results.0.provider_artist_id', 'sp-2');
        $response->assertJsonPath('results.0.follower_count', 5_000_000);
        $response->assertJsonStructure([
            'results' => [
                ['provider_artist_id', 'name', 'picture_url', 'follower_count'],
            ],
        ]);
    }

    public function test_artist_search_requires_authentication(): void
    {
        $this->getJson('/api/artists/search?q=drake')->assertUnauthorized();
    }

    public function test_artist_search_requires_a_minimum_query_length(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->getJson('/api/artists/search?q=a');

        $response->assertUnprocessable();
    }
}
