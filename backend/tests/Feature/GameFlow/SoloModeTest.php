<?php

namespace Tests\Feature\GameFlow;

use App\Enums\DifficultyTier;
use App\Events\RoundFailed;
use App\Events\RoundStageAdvanced;
use App\Events\RoundStarted;
use App\Events\RoundWon;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Song;
use App\Models\User;
use App\Services\RoundService;
use App\Support\SnippetStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SoloModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
    }

    private function seedSongsForAllTiers(int $count = 2): void
    {
        $this->fakeDeezerTrackRefresh();

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->count($count)->create();
        }
    }

    public function test_starting_a_solo_round_never_schedules_a_stage_timer(): void
    {
        Event::fake([RoundStarted::class]);

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'solo', 'songs_per_tier' => 1]);

        app(RoundService::class)->start($room);

        Queue::assertNotPushed(AdvanceRoundStage::class);
    }

    public function test_a_wrong_guess_immediately_advances_the_stage(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'solo', 'songs_per_tier' => 1]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $player = $room->players()->create([
            'nickname' => 'Solo',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => 'definitely not the song']);

        $response->assertOk();
        $response->assertJson(['correct' => false, 'won' => false]);

        $round->refresh();
        $this->assertSame(0.5, (float) $round->snippet_stage);
        $this->assertSame(2, $round->stage_version);
        $this->assertSame('playing', $round->status->value);

        Event::assertDispatched(RoundStageAdvanced::class);
        Queue::assertNotPushed(AdvanceRoundStage::class);
    }

    public function test_a_wrong_guess_at_the_last_stage_fails_the_round(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'solo', 'songs_per_tier' => 1]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();
        $round->update(['snippet_stage' => SnippetStage::SEQUENCE[count(SnippetStage::SEQUENCE) - 1]]);

        $player = $room->players()->create([
            'nickname' => 'Solo',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => 'definitely not the song'])
            ->assertOk();

        $round->refresh();
        $this->assertSame('failed', $round->status->value);

        Event::assertDispatched(RoundFailed::class);
    }

    public function test_a_correct_guess_still_wins_in_solo_mode(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'solo', 'songs_per_tier' => 1]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $player = $room->players()->create([
            'nickname' => 'Solo',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $round->song->title]);

        $response->assertOk();
        $response->assertJson(['correct' => true, 'won' => true]);

        $round->refresh();
        $this->assertSame('won', $round->status->value);
        Event::assertDispatched(RoundWon::class);
    }

    public function test_replaying_the_clip_is_free_and_does_not_touch_the_round(): void
    {
        Event::fake();

        // Free replay is entirely a frontend/audio-element concern (see
        // GamePlayPage.playClip()) - there is no backend endpoint or state
        // tied to "listening again", so nothing server-side needs to
        // change or be guarded for it. This test documents that a round
        // simply sits untouched until a guess (or nothing) happens to it.
        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['mode' => 'solo', 'songs_per_tier' => 1]);
        app(RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $this->assertSame(1, $round->stage_version);
        $this->assertSame(SnippetStage::first(), (float) $round->snippet_stage);
    }
}
