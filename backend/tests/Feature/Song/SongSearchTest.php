<?php

namespace Tests\Feature\Song;

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_a_player_can_search_the_pool_by_title_or_artist(): void
    {
        Song::factory()->create(['title' => 'Blinding Lights', 'artist' => 'The Weeknd', 'popularity' => 92]);
        Song::factory()->create(['title' => 'Save Your Tears', 'artist' => 'The Weeknd', 'popularity' => 80]);
        Song::factory()->create(['title' => 'Something Else', 'artist' => 'Nobody', 'popularity' => 50]);

        $player = $this->player();

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=weeknd');

        $response->assertOk();
        $response->assertJsonCount(2, 'results');
        // Most popular first.
        $response->assertJsonPath('results.0.title', 'Blinding Lights');
        $response->assertJsonStructure([
            'results' => [
                ['provider_track_id', 'title', 'artist', 'album_art_url'],
            ],
        ]);
    }

    public function test_search_matches_a_partial_title(): void
    {
        Song::factory()->create(['title' => 'Bohemian Rhapsody', 'artist' => 'Queen']);
        $player = $this->player();

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=rhaps')
            ->assertOk()
            ->assertJsonPath('results.0.title', 'Bohemian Rhapsody');
    }

    /**
     * Postgres LIKE is case-sensitive (sqlite/mysql aren't); the controller
     * LOWER()s both sides so a lowercase query still finds a Title Case song.
     */
    public function test_search_is_case_insensitive(): void
    {
        Song::factory()->create(['title' => 'Blinding Lights', 'artist' => 'The Weeknd']);
        $player = $this->player();

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=blinding')
            ->assertOk()
            ->assertJsonPath('results.0.title', 'Blinding Lights');

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=WEEKND')
            ->assertOk()
            ->assertJsonPath('results.0.artist', 'The Weeknd');
    }

    public function test_search_excludes_flagged_songs(): void
    {
        Song::factory()->create(['title' => 'Hidden Track', 'artist' => 'X', 'excluded' => true]);
        $player = $this->player();

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->getJson('/api/songs/search?q=hidden')
            ->assertOk()
            ->assertJsonCount(0, 'results');
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
