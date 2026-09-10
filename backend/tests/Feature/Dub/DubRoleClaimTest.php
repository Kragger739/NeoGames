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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DubRoleClaimTest extends TestCase
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

    public function test_start_moves_to_role_claim_and_seeds_one_unassigned_row_per_character(): void
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip(characters: 3, lines: 6);
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $this->seatHost($room);
        $this->addPlayer($room);

        $this->service->start($room->fresh());

        $game = $room->fresh()->dubGame;
        $this->assertSame(DubGameState::RoleClaim, $game->state);
        $this->assertSame(1, $game->round_number);
        $this->assertSame(3, $game->roleAssignments()->where('round_number', 1)->count());
        $this->assertSame(0, $game->roleAssignments()->whereNotNull('room_player_id')->count());
    }

    public function test_start_is_rejected_without_two_mic_ready_players(): void
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip();
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $this->seatHost($room);
        $this->addPlayer($room, ['mic_ready' => false]);

        $this->expectException(ValidationException::class);
        $this->service->start($room->fresh());
    }

    public function test_claiming_a_character_already_held_by_someone_else_is_rejected(): void
    {
        [$room, $clip, $p1, $p2] = $this->startedRoom();
        $character = $clip->characters->first();

        $this->service->claimRole($room->fresh(), $p1, $character);

        $this->expectException(ValidationException::class);
        $this->service->claimRole($room->fresh(), $p2, $character);
    }

    public function test_a_player_holds_only_one_character_at_a_time(): void
    {
        [$room, $clip, $p1] = $this->startedRoom();
        [$c1, $c2] = [$clip->characters[0], $clip->characters[1]];

        $this->service->claimRole($room->fresh(), $p1, $c1);
        $this->service->claimRole($room->fresh(), $p1, $c2);

        $game = $room->fresh()->dubGame;
        $this->assertNull($game->roleAssignments()->where('dub_clip_character_id', $c1->id)->first()->room_player_id);
        $this->assertSame($p1->id, $game->roleAssignments()->where('dub_clip_character_id', $c2->id)->first()->room_player_id);
    }

    public function test_begin_recording_needs_at_least_one_claim(): void
    {
        [$room] = $this->startedRoom();

        $this->expectException(ValidationException::class);
        $this->service->beginRecording($room->fresh());
    }

    public function test_begin_recording_starts_on_the_first_claimed_line(): void
    {
        [$room, $clip, $p1] = $this->startedRoom(characters: 2, lines: 6);
        // Claim only the SECOND character - its first line is at position 1.
        $this->service->claimRole($room->fresh(), $p1, $clip->characters[1]);

        $this->service->beginRecording($room->fresh());

        $game = $room->fresh()->dubGame;
        $this->assertSame(DubGameState::Recording, $game->state);
        $this->assertSame(1, $game->current_line_index);
    }

    /** @return array{0: GameRoom, 1: DubClip, 2: RoomPlayer, 3: RoomPlayer} */
    private function startedRoom(int $characters = 2, int $lines = 6): array
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip($characters, $lines);
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $p1 = $this->seatHost($room);
        $p2 = $this->addPlayer($room);

        $this->service->start($room->fresh());

        return [$room, $clip->fresh()->load('characters'), $p1, $p2];
    }
}
