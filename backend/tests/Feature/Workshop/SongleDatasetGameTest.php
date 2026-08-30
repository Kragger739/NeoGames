<?php

namespace Tests\Feature\Workshop;

use App\Enums\DifficultyTier;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\Dataset;
use App\Models\GameRoom;
use App\Models\Song;
use App\Models\User;
use App\Services\RoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SongleDatasetGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
        Event::fake();
    }

    private function songleDatasetWith(User $owner, int $tracks, string $visibility = 'private'): Dataset
    {
        $dataset = Dataset::create([
            'owner_id' => $owner->id, 'name' => 'My Mix', 'type' => 'songle', 'visibility' => $visibility,
        ]);

        for ($i = 0; $i < $tracks; $i++) {
            $dataset->tracks()->create([
                'deezer_track_id' => "trk-{$i}", 'title' => "Song {$i}", 'artist' => 'Artist',
                'preview_url' => "https://example.com/{$i}.mp3", 'position' => $i,
            ]);
        }

        return $dataset;
    }

    public function test_selecting_a_dataset_in_the_lobby_forces_a_flat_pool_and_round_count(): void
    {
        $host = User::factory()->create();
        $dataset = $this->songleDatasetWith($host, 5);

        $create = $this->actingAs($host)->postJson('/api/rooms');
        $code = $create->json('code');

        $patch = $this->actingAs($host)->patchJson("/api/rooms/{$code}", [
            'dataset_id' => $dataset->id,
            'songs_per_tier' => 5,
        ]);

        $patch->assertOk();
        $patch->assertJsonPath('dataset_id', $dataset->id);
        $patch->assertJsonPath('dataset_name', 'My Mix');
        $patch->assertJsonPath('enabled_tiers', ['easy']);
        $patch->assertJsonPath('songs_per_tier', 5);
        $patch->assertJsonPath('genre', 'normal');
    }

    public function test_rounds_are_drawn_from_the_dataset_tracks(): void
    {
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $dataset = $this->songleDatasetWith($host, 4);

        $room = GameRoom::factory()->for($host, 'host')->create([
            'genre' => 'normal',
            'dataset_id' => $dataset->id,
            'enabled_tiers' => ['easy'],
            'songs_per_tier' => 4,
        ]);

        $round = app(RoundService::class)->start($room);

        $this->assertContains($round->song->deezer_track_id, ['trk-0', 'trk-1', 'trk-2', 'trk-3']);
        $this->assertDatabaseHas('songs', ['deezer_track_id' => $round->song->deezer_track_id]);
    }

    public function test_a_room_with_no_dataset_selects_songs_the_normal_way(): void
    {
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $song = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'iconic']);

        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'classic', 'genre' => 'iconic', 'songs_per_tier' => 1, 'dataset_id' => null,
        ]);

        $round = app(RoundService::class)->start($room);

        $this->assertSame($song->id, $round->song_id);
    }

    public function test_starting_a_game_with_an_empty_dataset_fails_cleanly(): void
    {
        $host = User::factory()->create();
        $empty = Dataset::create(['owner_id' => $host->id, 'name' => 'Empty', 'type' => 'songle', 'visibility' => 'private']);

        $room = GameRoom::factory()->for($host, 'host')->create([
            'genre' => 'normal', 'dataset_id' => $empty->id, 'enabled_tiers' => ['easy'], 'songs_per_tier' => 5,
        ]);

        // Seat a player so start() gets past its own checks, then expect a
        // clean 422 (RoundService throws -> GameRoomController::start catches).
        $create = $this->actingAs($host)->postJson('/api/rooms');
        $liveCode = $create->json('code');
        $this->actingAs($host)->patchJson("/api/rooms/{$liveCode}", ['dataset_id' => $empty->id]);

        $this->actingAs($host)->postJson("/api/rooms/{$liveCode}/start")
            ->assertUnprocessable()->assertJsonValidationErrors('room');
    }

    public function test_a_ddf_dataset_cannot_be_used_as_a_song_source(): void
    {
        $host = User::factory()->create();
        $ddf = Dataset::create(['owner_id' => $host->id, 'name' => 'Q', 'type' => 'ddf', 'visibility' => 'private', 'language' => 'en']);

        $code = $this->actingAs($host)->postJson('/api/rooms')->json('code');

        $this->actingAs($host)->patchJson("/api/rooms/{$code}", ['dataset_id' => $ddf->id])
            ->assertUnprocessable()->assertJsonValidationErrors('dataset_id');
    }
}
