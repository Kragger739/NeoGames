<?php

namespace Tests\Feature\Dub;

use App\Enums\DubGameState;
use App\Jobs\AdvanceDubState;
use App\Jobs\AssembleDubVideo;
use App\Services\DubGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DubStateMachineTest extends TestCase
{
    use CreatesDubRooms, RefreshDatabase;

    private DubGameService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake([AdvanceDubState::class, AssembleDubVideo::class]);
        $this->service = app(DubGameService::class);
    }

    public function test_a_stale_advance_dub_state_fire_is_a_no_op(): void
    {
        $room = $this->createDubRoom(['state' => 'recording', 'state_version' => 7, 'current_line_index' => 0]);
        $clip = $this->makeReadyClip();
        $room->dubGame->update(['dub_clip_id' => $clip->id]);

        // Expected version 6, actual is 7 -> nothing happens.
        $this->service->handleTimerExpired($room->dubGame->id, 6);

        $this->assertSame(DubGameState::Recording, $room->fresh()->dubGame->state);
        $this->assertSame(7, $room->fresh()->dubGame->state_version);
    }

    public function test_restart_wipes_round_data_and_returns_to_lobby(): void
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip(characters: 2, lines: 4);
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $p1 = $this->seatHost($room);
        $p2 = $this->addPlayer($room);
        $this->service->start($room->fresh());
        $this->service->claimRole($room->fresh(), $p1, $clip->fresh()->characters[0]);

        $this->service->restart($room->fresh());

        $game = $room->fresh()->dubGame;
        $this->assertSame(DubGameState::Lobby, $game->state);
        $this->assertSame(0, $game->round_number);
        $this->assertSame(0, $game->roleAssignments()->count());
        $this->assertEqualsWithDelta(0.0, $game->total_score, 0.001);
        $this->assertFalse((bool) $p1->fresh()->dubState->mic_ready);
    }

    public function test_on_assembly_failed_keeps_the_game_in_assembling_with_an_error(): void
    {
        $room = $this->createDubRoom(['state' => 'assembling', 'state_version' => 3]);
        $this->makeReadyClip();

        $this->service->onAssemblyFailed($room->fresh()->dubGame, 'ffmpeg exited 1');

        $game = $room->fresh()->dubGame;
        $this->assertSame(DubGameState::Assembling, $game->state);
        $this->assertStringContainsString('ffmpeg', (string) $game->assembly_error);
    }
}
