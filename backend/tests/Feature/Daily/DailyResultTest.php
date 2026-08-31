<?php

namespace Tests\Feature\Daily;

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

class DailyResultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
        $this->fakeDeezerTrackRefresh();
        Event::fake();
    }

    public function test_finishing_the_daily_records_the_result_on_the_attempt(): void
    {
        Song::factory()->count(12)->create(['genre' => 'iconic']);
        $host = User::factory()->create();

        $this->actingAs($host)->postJson('/api/daily/start')->assertCreated();

        $room = GameRoom::where('host_id', $host->id)->first();
        $round = $room->rounds()->latest('id')->first();
        $round->update(['status' => 'won']);
        $room->players()->where('user_id', $host->id)->update(['score' => 42]);

        app(RoundService::class)->finishGame($room->fresh(), $round->id);

        $this->assertDatabaseHas('daily_challenge_attempts', [
            'daily_challenge_id' => $room->daily_challenge_id,
            'user_id' => $host->id,
            'score' => 42,
            'correct_count' => 1,
        ]);
        $this->assertNotNull(
            $room->dailyChallenge->attempts()->where('user_id', $host->id)->first()->finished_at,
        );

        $this->actingAs($host)->getJson('/api/daily')
            ->assertJsonPath('played', true)
            ->assertJsonPath('finished', true)
            ->assertJsonPath('best_score', 42);
    }

    public function test_a_daily_room_cannot_be_redone(): void
    {
        Song::factory()->count(12)->create(['genre' => 'iconic']);
        $host = User::factory()->create();

        $this->actingAs($host)->postJson('/api/daily/start')->assertCreated();

        $room = GameRoom::where('host_id', $host->id)->first();
        $round = $room->rounds()->latest('id')->first();
        $round->update(['status' => 'won']);
        app(RoundService::class)->finishGame($room->fresh(), $round->id);

        $this->actingAs($host)->postJson("/api/rooms/{$room->code}/redo")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room');

        $this->assertSame('finished', $room->fresh()->status->value);
    }
}
