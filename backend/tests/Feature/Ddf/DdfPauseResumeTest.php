<?php

namespace Tests\Feature\Ddf;

use App\Jobs\AdvanceDdfGameState;
use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DdfPauseResumeTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([AdvanceDdfGameState::class]);
        Event::fake();
    }

    public function test_pausing_invalidates_the_pending_timer_and_captures_remaining_seconds(): void
    {
        $room = $this->createDdfRoom([
            'state' => 'question',
            'question_timer_seconds' => 30,
            'stage_started_at' => now()->subSeconds(10),
        ]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);

        $originalVersion = $room->ddfGame->state_version;

        app(DdfGameService::class)->pause($room->fresh());

        $game = $room->fresh()->ddfGame;
        $this->assertTrue($game->is_paused);
        $this->assertGreaterThan($originalVersion, $game->state_version);
        $this->assertEqualsWithDelta(20, $game->paused_remaining_seconds, 1);

        // The original timer job (dispatched at start with the old version)
        // firing now would find state_version mismatched - a safe no-op,
        // proven directly by handleTimerExpired() in DdfQuestionTimerTest.
    }

    public function test_resuming_redispatches_with_the_captured_remaining_seconds(): void
    {
        $room = $this->createDdfRoom([
            'state' => 'question',
            'question_timer_seconds' => 30,
            'stage_started_at' => now()->subSeconds(10),
            'couch_mode' => false,
        ]);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);

        $service = app(DdfGameService::class);
        $service->pause($room->fresh());
        $service->resume($room->fresh());

        $game = $room->fresh()->ddfGame;
        $this->assertFalse($game->is_paused);
        $this->assertNull($game->paused_remaining_seconds);

        Queue::assertPushed(
            AdvanceDdfGameState::class,
            fn ($job) => $job->ddfGameId === $game->id && $job->expectedStateVersion === $game->state_version,
        );
    }
}
