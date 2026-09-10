<?php

namespace Tests\Feature\Dub;

use App\Enums\DubGameState;
use App\Jobs\AdvanceDubState;
use App\Jobs\AssembleDubVideo;
use App\Models\DubClip;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Services\DubGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DubScoringTest extends TestCase
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

    public function test_assembly_completion_moves_through_watch_and_rating_to_a_scored_round(): void
    {
        [$room, $p1, $p2] = $this->assemblingRoom();

        $this->service->onAssemblyComplete($room->fresh()->dubGame, 'dub/games/1/round-1.mp4');
        $this->assertSame(DubGameState::Watch, $room->fresh()->dubGame->state);
        $this->assertSame('dub/games/1/round-1.mp4', $room->fresh()->dubGame->assembled_video_path);

        $this->service->markWatched($room->fresh());
        $this->assertSame(DubGameState::Rating, $room->fresh()->dubGame->state);

        $this->service->submitRating($room->fresh(), $p1, 5);
        $this->assertSame(DubGameState::Rating, $room->fresh()->dubGame->state); // still waiting on p2
        $this->service->submitRating($room->fresh(), $p2, 4);

        $game = $room->fresh()->dubGame;
        $this->assertSame(DubGameState::RoundComplete, $game->state);
        $this->assertEqualsWithDelta(4.5, $game->last_round_score, 0.001);
        $this->assertEqualsWithDelta(4.5, $game->total_score, 0.001);
    }

    public function test_a_second_round_score_accumulates_into_total_score(): void
    {
        [$room, $p1, $p2, $clip] = $this->assemblingRoom();
        $this->service->onAssemblyComplete($room->fresh()->dubGame, 'dub/r1.mp4');
        $this->service->markWatched($room->fresh());
        $this->service->submitRating($room->fresh(), $p1, 2);
        $this->service->submitRating($room->fresh(), $p2, 4); // round 1 avg = 3.0

        $this->service->nextRound($room->fresh(), $clip);
        $this->assertSame(DubGameState::RoleClaim, $room->fresh()->dubGame->state);
        $this->assertSame(2, $room->fresh()->dubGame->round_number);

        // Drive round 2 to a score.
        $this->driveToAssembling($room, $p1, $p2, $clip);
        $this->service->onAssemblyComplete($room->fresh()->dubGame, 'dub/r2.mp4');
        $this->service->markWatched($room->fresh());
        $this->service->submitRating($room->fresh(), $p1, 5);
        $this->service->submitRating($room->fresh(), $p2, 5); // round 2 avg = 5.0

        $this->assertEqualsWithDelta(8.0, $room->fresh()->dubGame->total_score, 0.001);
    }

    public function test_finish_moves_the_game_to_finished(): void
    {
        [$room, $p1, $p2] = $this->assemblingRoom();
        $this->service->onAssemblyComplete($room->fresh()->dubGame, 'dub/r1.mp4');
        $this->service->markWatched($room->fresh());
        $this->service->submitRating($room->fresh(), $p1, 3);
        $this->service->submitRating($room->fresh(), $p2, 3);

        $this->service->finish($room->fresh());

        $this->assertSame(DubGameState::Finished, $room->fresh()->dubGame->state);
    }

    /** @return array{0: GameRoom, 1: RoomPlayer, 2: RoomPlayer, 3: DubClip} */
    private function assemblingRoom(): array
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip(characters: 2, lines: 4);
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $p1 = $this->seatHost($room);
        $p2 = $this->addPlayer($room);

        $this->service->start($room->fresh());
        $this->driveToAssembling($room, $p1, $p2, $clip->fresh()->load(['characters', 'lines']));

        return [$room, $p1, $p2, $clip->fresh()->load(['characters', 'lines'])];
    }

    private function driveToAssembling(GameRoom $room, $p1, $p2, $clip): void
    {
        $clip = $clip->fresh()->load(['characters', 'lines']);
        $this->service->claimRole($room->fresh(), $p1, $clip->characters[0]);
        $this->service->claimRole($room->fresh(), $p2, $clip->characters[1]);
        $this->service->beginRecording($room->fresh());

        $guard = 0;
        while ($room->fresh()->dubGame->state === DubGameState::Recording && $guard++ < 20) {
            $game = $room->fresh()->dubGame;
            $line = $clip->lines->firstWhere('position', $game->current_line_index);
            $ownerId = $game->roleAssignments()
                ->where('round_number', $game->round_number)
                ->where('dub_clip_character_id', $line->dub_clip_character_id)
                ->value('room_player_id');
            $owner = $ownerId === $p1->id ? $p1 : $p2;
            $this->service->submitTake($room->fresh(), $owner, $line, "dub/take-{$line->id}.webm", 900);
        }

        $this->assertSame(DubGameState::Assembling, $room->fresh()->dubGame->state);
    }
}
