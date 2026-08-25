<?php

namespace Tests\Feature\GameFlow;

use App\Enums\DifficultyTier;
use App\Events\RoundFailed;
use App\Events\RoundStageAdvanced;
use App\Events\RoundStarted;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\Song;
use App\Models\User;
use App\Services\RoundService;
use App\Support\SnippetStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RoundTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
    }

    private function hostWithFullLibrary(int $songsPerTier = 1): User
    {
        $this->fakeDeezerTrackRefresh();

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->count($songsPerTier)->create();
        }

        return User::factory()->create();
    }

    public function test_a_timeout_advances_the_snippet_stage_and_reschedules_the_next_timeout(): void
    {
        Event::fake([RoundStageAdvanced::class, RoundStarted::class]);

        $host = $this->hostWithFullLibrary();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        $round = app(RoundService::class)->start($room);

        $this->assertSame(SnippetStage::first(), (float) $round->snippet_stage);

        app(RoundService::class)->handleStageTimeout($round->id, 1);

        $round->refresh();
        $this->assertSame(0.5, (float) $round->snippet_stage);
        $this->assertSame(2, $round->stage_version);
        $this->assertSame('playing', $round->status->value);

        Event::assertDispatched(RoundStageAdvanced::class);
        Queue::assertPushed(AdvanceRoundStage::class, fn ($job) => $job->roundId === $round->id && $job->expectedStageVersion === 2);
    }

    /**
     * The guessing grace period must start once the clip has finished
     * playing, not concurrently with it - otherwise a long stage (e.g. the
     * final 15s one) could be force-escalated or fail before its own audio
     * even finishes.
     */
    public function test_the_next_timeouts_delay_includes_the_new_stages_snippet_duration(): void
    {
        Event::fake([RoundStageAdvanced::class, RoundStarted::class]);

        $host = $this->hostWithFullLibrary();
        $room = GameRoom::factory()->for($host, 'host')->create([
            'songs_per_tier' => 1,
            'guess_timeout_seconds' => 8,
        ]);
        $round = app(RoundService::class)->start($room);

        app(RoundService::class)->handleStageTimeout($round->id, 1);

        // Stage just advanced from 0.1s to 0.5s - the next timeout's delay
        // must be 0.5 (new stage's clip length) + 8 (guess_timeout_seconds),
        // not just 8.
        Queue::assertPushed(AdvanceRoundStage::class, function ($job) {
            $expected = now()->addSeconds(0.5 + 8);

            return $job->expectedStageVersion === 2
                && $job->delay !== null
                && abs($job->delay->getTimestamp() - $expected->getTimestamp()) <= 1;
        });
    }

    public function test_starting_a_round_delays_the_first_timeout_by_the_first_stages_snippet_duration(): void
    {
        Event::fake([RoundStarted::class]);

        $host = $this->hostWithFullLibrary();
        $room = GameRoom::factory()->for($host, 'host')->create([
            'songs_per_tier' => 1,
            'guess_timeout_seconds' => 8,
        ]);

        app(RoundService::class)->start($room);

        // First stage is 0.1s - the very first timeout's delay must be
        // 0.1 + 8, not a bare 8.
        Queue::assertPushed(AdvanceRoundStage::class, function ($job) {
            $expected = now()->addSeconds(0.1 + 8);

            return $job->expectedStageVersion === 1
                && $job->delay !== null
                && abs($job->delay->getTimestamp() - $expected->getTimestamp()) <= 1;
        });
    }

    public function test_a_timeout_at_the_last_stage_fails_the_round_and_reveals_the_answer(): void
    {
        Event::fake([RoundFailed::class, RoundStarted::class]);

        $host = $this->hostWithFullLibrary(songsPerTier: 2);
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 2]);
        $round = app(RoundService::class)->start($room);
        $round->song->update(['album_art_url' => 'https://example.com/art-100.jpg']);

        $round->update(['snippet_stage' => SnippetStage::SEQUENCE[count(SnippetStage::SEQUENCE) - 1]]);

        app(RoundService::class)->handleStageTimeout($round->id, 1);

        $round->refresh();
        $this->assertSame('failed', $round->status->value);

        Event::assertDispatched(
            RoundFailed::class,
            fn (RoundFailed $event) => $event->broadcastWith()['answer']['album_art_url'] === 'https://example.com/art-100.jpg',
        );
    }

    public function test_a_stale_timeout_is_a_no_op_when_the_round_was_already_won(): void
    {
        Event::fake();

        $host = $this->hostWithFullLibrary();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        $round = app(RoundService::class)->start($room);

        $round->update(['status' => 'won']);

        app(RoundService::class)->handleStageTimeout($round->id, 1);

        $round->refresh();
        $this->assertSame('won', $round->status->value);
        $this->assertSame(0.1, (float) $round->snippet_stage);

        Event::assertNotDispatched(RoundStageAdvanced::class);
        Event::assertNotDispatched(RoundFailed::class);
    }

    public function test_a_stale_timeout_is_a_no_op_when_a_newer_timer_already_advanced_the_stage(): void
    {
        Event::fake([RoundStageAdvanced::class, RoundStarted::class]);

        $host = $this->hostWithFullLibrary();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        $round = app(RoundService::class)->start($room);

        // Simulate a newer timer having already fired for this round.
        app(RoundService::class)->handleStageTimeout($round->id, 1);
        $round->refresh();
        $this->assertSame(2, $round->stage_version);

        Event::fake([RoundStageAdvanced::class, RoundStarted::class]);

        // A stale duplicate of the original (version 1) timer fires late.
        app(RoundService::class)->handleStageTimeout($round->id, 1);

        $round->refresh();
        $this->assertSame(2, $round->stage_version);
        $this->assertSame(0.5, (float) $round->snippet_stage);

        Event::assertNotDispatched(RoundStageAdvanced::class);
    }
}
