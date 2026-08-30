<?php

namespace Tests\Feature\Song;

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SongSearchTest extends TestCase
{
    use RefreshDatabase;

    private function player(): RoomPlayer
    {
        $room = GameRoom::factory()->create();

        return $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);
    }

    public function test_a_player_can_search_for_songs_by_title(): void
    {
        $this->fakeSpotifyToken();
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response([
                'tracks' => ['items' => [
                    [
                        'id' => 'sp-123',
                        'name' => 'Blinding Lights',
                        'popularity' => 92,
                        'external_ids' => ['isrc' => 'USUG11904206'],
                        'artists' => [['id' => 'sp-w', 'name' => 'The Weeknd']],
                        'album' => ['release_date' => '2019-11-29', 'images' => [['url' => 'https://example.com/art.jpg']]],
                    ],
                ]],
            ], 200),
        ]);

        $player = $this->player();

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=blinding+lights');

        $response->assertOk();
        $response->assertJsonCount(1, 'results');
        $response->assertJsonPath('results.0.title', 'Blinding Lights');
        $response->assertJsonPath('results.0.artist', 'The Weeknd');
        $response->assertJsonPath('results.0.provider_track_id', 'sp-123');
        $response->assertJsonStructure([
            'results' => [
                ['provider_track_id', 'title', 'artist', 'album_art_url'],
            ],
        ]);
    }

    public function test_search_requires_authentication(): void
    {
        $this->getJson('/api/songs/search?q=love')->assertUnauthorized();
    }

    public function test_search_requires_a_minimum_query_length(): void
    {
        $player = $this->player();

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=a');

        $response->assertUnprocessable();
    }
}
