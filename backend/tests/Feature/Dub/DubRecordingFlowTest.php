<?php

namespace Tests\Feature\Dub;

use App\Enums\DubGameState;
use App\Jobs\AdvanceDubState;
use App\Jobs\AssembleDubVideo;
use App\Models\DubClip;
use App\Models\DubClipLine;
use App\Models\DubTake;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Services\DubGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DubRecordingFlowTest extends TestCase
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

    public function test_only_the_assigned_player_can_submit_the_current_line_take(): void
    {
        [$room, $line, $owner, $other] = $this->recordingRoom();

        $this->expectException(ValidationException::class);
        $this->service->submitTake($room->fresh(), $other, $line, 'dub/x.webm', 1200);
    }

    public function test_a_retake_supersedes_the_previous_take_for_that_line(): void
    {
        [$room, $line, $owner] = $this->recordingRoom();

        $this->service->submitTake($room->fresh(), $owner, $line, 'dub/take-a.webm', 1000);
        // Line index advanced; move it back so the owner can retake the same line.
        $room->fresh()->dubGame->update(['current_line_index' => $line->position]);
        $this->service->submitTake($room->fresh(), $owner, $line, 'dub/take-b.webm', 1100);

        $selected = DubTake::where('dub_clip_line_id', $line->id)->where('is_selected', true)->get();
        $this->assertCount(1, $selected);
        $this->assertSame('dub/take-b.webm', $selected->first()->audio_path);
        $this->assertSame(2, DubTake::where('dub_clip_line_id', $line->id)->count());
    }

    public function test_recording_every_claimed_line_enters_assembling_and_queues_the_assembly_job(): void
    {
        // One character claimed => only that character's lines are recorded.
        [$room, , $owner, , $clip] = $this->recordingRoom(characters: 2, lines: 6, claimSecond: false);

        $ownedLines = $clip->lines->where('dub_clip_character_id', $clip->characters[0]->id)->values();
        $this->assertCount(3, $ownedLines);

        foreach ($ownedLines as $line) {
            $game = $room->fresh()->dubGame;
            $this->assertSame(DubGameState::Recording, $game->state);
            $this->assertSame($line->position, $game->current_line_index);
            $this->service->submitTake($room->fresh(), $owner, $line, "dub/take-{$line->id}.webm", 1000);
        }

        $this->assertSame(DubGameState::Assembling, $room->fresh()->dubGame->state);
        Queue::assertPushed(AssembleDubVideo::class);
    }

    public function test_host_advance_line_skips_the_current_line_without_a_take(): void
    {
        [$room, $line, $owner] = $this->recordingRoom();

        $this->service->advanceLine($room->fresh());

        $this->assertSame(0, DubTake::count());
        $this->assertGreaterThan($line->position, $room->fresh()->dubGame->current_line_index);
    }

    /**
     * @return array{0: GameRoom, 1: DubClipLine, 2: RoomPlayer, 3: RoomPlayer, 4: DubClip}
     */
    private function recordingRoom(int $characters = 2, int $lines = 6, bool $claimSecond = true): array
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip($characters, $lines);
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $p1 = $this->seatHost($room);
        $p2 = $this->addPlayer($room);

        $this->service->start($room->fresh());
        $clip = $clip->fresh()->load(['characters', 'lines']);

        $this->service->claimRole($room->fresh(), $p1, $clip->characters[0]);
        if ($claimSecond) {
            $this->service->claimRole($room->fresh(), $p2, $clip->characters[1]);
        }
        $this->service->beginRecording($room->fresh());

        $currentIndex = $room->fresh()->dubGame->current_line_index;
        $line = DubClipLine::where('dub_clip_id', $clip->id)->where('position', $currentIndex)->firstOrFail();

        return [$room, $line, $p1, $p2, $clip];
    }
}
