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
        Http::fake([
            'api.deezer.com/search*' => Http::response([
                'data' => [
                    [
                        'id' => 123,
                        'title' => 'Blinding Lights',
                        'artist' => ['name' => 'The Weeknd'],
                        'album' => ['cover_medium' => 'https://example.com/art.jpg'],
                        'preview' => 'https://example.com/preview.mp3',
                        'rank' => 900_000,
                    ],
                    [
                        'id' => 456,
                        'title' => 'No Preview Track',
                        'artist' => ['name' => 'Someone'],
                        'album' => ['cover_medium' => null],
                        'preview' => null,
                        'rank' => 100_000,
                    ],
                ],
            ], 200),
        ]);

        $player = $this->player();

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=blinding+lights');

        $response->assertOk();
        $response->assertJsonCount(1, 'results');
        $response->assertJsonPath('results.0.title', 'Blinding Lights');
        $response->assertJsonPath('results.0.artist', 'The Weeknd');
        $response->assertJsonStructure([
            'results' => [
                ['deezer_track_id', 'title', 'artist', 'album_art_url', 'preview_url'],
            ],
        ]);
        $response->assertJsonPath('results.0.preview_url', 'https://example.com/preview.mp3');
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
