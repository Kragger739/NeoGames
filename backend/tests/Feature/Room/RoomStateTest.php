<?php

namespace Tests\Feature\Room;

use App\Enums\DifficultyTier;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\Song;
use App\Models\User;
use App\Services\RoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RoomStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
        Event::fake();
    }

    public function test_room_state_includes_no_current_round_before_the_game_starts(): void
    {
        $room = GameRoom::factory()->create();

        $response = $this->getJson("/api/rooms/{$room->code}");

        $response->assertOk();
        $response->assertJsonPath('current_round', null);
    }

    public function test_room_state_includes_the_in_progress_round_without_the_answer(): void
    {
        $this->fakeDeezerTrackRefresh();

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->create(['title' => 'Secret Title']);
        }
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        $round = app(RoundService::class)->start($room);

        $response = $this->getJson("/api/rooms/{$room->code}");

        $response->assertOk();
        $response->assertJsonPath('current_round.round_id', $round->id);
        $response->assertJsonPath('current_round.stage', 0.1);
        $response->assertJsonMissingPath('current_round.title');

        $this->assertStringNotContainsString('Secret Title', $response->getContent());
    }

    public function test_room_state_players_are_sorted_by_score_descending(): void
    {
        $room = GameRoom::factory()->create();
        $room->players()->create(['nickname' => 'Low', 'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(), 'score' => 10]);
        $room->players()->create(['nickname' => 'High', 'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(), 'score' => 90]);

        $response = $this->getJson("/api/rooms/{$room->code}");

        $response->assertOk();
        $response->assertJsonPath('players.0.nickname', 'High');
        $response->assertJsonPath('players.1.nickname', 'Low');
    }
}
