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
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Small Fan Count', 'picture_medium' => 'https://example.com/a.jpg', 'nb_fan' => 100],
                    ['id' => 2, 'name' => 'Real Artist', 'picture_medium' => 'https://example.com/b.jpg', 'nb_fan' => 5_000_000],
                ],
            ], 200),
        ]);

        $host = User::factory()->create();

        $response = $this->actingAs($host)->getJson('/api/artists/search?q=artist');

        $response->assertOk();
        $response->assertJsonCount(2, 'results');
        // Sorted by fan count, not Deezer's own result order.
        $response->assertJsonPath('results.0.name', 'Real Artist');
        $response->assertJsonPath('results.0.deezer_artist_id', '2');
        $response->assertJsonPath('results.0.fan_count', 5_000_000);
        $response->assertJsonStructure([
            'results' => [
                ['deezer_artist_id', 'name', 'picture_url', 'fan_count'],
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
