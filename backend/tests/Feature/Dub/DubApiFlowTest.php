<?php

namespace Tests\Feature\Dub;

use App\Enums\DubGameState;
use App\Jobs\AdvanceDubState;
use App\Jobs\AssembleDubVideo;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\DubGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Route wiring + guard enforcement for /dub-rooms/*. Following this
 * codebase's convention (see tests/Feature/GameFlow/*), each test drives at
 * most one actor over HTTP and uses DubGameService for the rest - a
 * viaRequest "player" guard caches its user across test requests, so
 * switching X-Player-Token mid-test is unreliable. The full state-machine
 * flow is covered at the service level in DubScoringTest / DubRecordingFlowTest.
 */
class DubApiFlowTest extends TestCase
{
    use CreatesDubRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake([AssembleDubVideo::class, AdvanceDubState::class]);
    }

    public function test_a_player_token_cannot_reach_a_host_only_route(): void
    {
        $room = $this->createDubRoom();
        $this->seatHost($room);
        $player = $this->addPlayer($room);

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/dub-rooms/{$room->code}/start")
            ->assertStatus(401);
    }

    public function test_joining_a_dub_room_creates_a_dub_player_state(): void
    {
        $room = $this->createDubRoom();
        $this->seatHost($room);
        $guest = User::factory()->create();

        $join = $this->actingAs($guest)->postJson("/api/rooms/{$room->code}/join")->assertCreated();

        $seat = RoomPlayer::where('connection_token', $join->json('connection_token'))->firstOrFail();
        $this->assertSame($room->id, $seat->room_id);
        $this->assertNotNull($seat->dubState);
        $this->assertFalse((bool) $seat->dubState->mic_ready);
    }

    public function test_player_can_mic_ready_then_claim_a_character_over_http(): void
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip(characters: 2, lines: 4);
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $host = $this->seatHost($room, ['mic_ready' => false]);
        $other = $this->addPlayer($room);

        // Player-guard route: mic ready.
        $this->withHeader('X-Player-Token', $host->connection_token)
            ->patchJson("/api/dub-rooms/{$room->code}/mic-ready", ['mic_ready' => true])
            ->assertOk()
            ->assertJsonPath('mic_ready', true);
        $this->assertTrue((bool) $host->fresh()->dubState->mic_ready);

        // Move into role-claim via the service, then claim over HTTP.
        app(DubGameService::class)->start($room->fresh());

        $character = $clip->fresh()->characters->first();
        $this->withHeader('X-Player-Token', $host->connection_token)
            ->postJson("/api/dub-rooms/{$room->code}/claims", ['character_id' => $character->id])
            ->assertNoContent();

        $this->assertSame(
            $host->id,
            $room->fresh()->dubGame->roleAssignments()
                ->where('dub_clip_character_id', $character->id)
                ->value('room_player_id'),
        );
    }

    public function test_host_selects_a_clip_and_starts_over_http(): void
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip(characters: 2, lines: 4);
        // Seats + readiness prepared via the trait/service so this test's only
        // HTTP actor is the host on the sanctum routes.
        $this->seatHost($room, ['mic_ready' => true]);
        $this->addPlayer($room, ['mic_ready' => true]);

        $this->actingAs($room->host)
            ->postJson("/api/dub-rooms/{$room->code}/clip", ['clip_id' => $clip->id])
            ->assertOk()
            ->assertJsonPath('clip.id', $clip->id);

        $this->actingAs($room->host)
            ->postJson("/api/dub-rooms/{$room->code}/start")
            ->assertOk()
            ->assertJsonPath('state', 'role_claim');

        $this->assertSame(DubGameState::RoleClaim, $room->fresh()->dubGame->state);
    }

    public function test_show_returns_the_finished_summary_after_the_service_runs_a_round(): void
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip(characters: 2, lines: 4);
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $p1 = $this->seatHost($room);
        $p2 = $this->addPlayer($room);
        $service = app(DubGameService::class);

        $service->start($room->fresh());
        $clip = $clip->fresh()->load(['characters', 'lines']);
        $service->claimRole($room->fresh(), $p1, $clip->characters[0]);
        $service->claimRole($room->fresh(), $p2, $clip->characters[1]);
        $service->beginRecording($room->fresh());

        $guard = 0;
        while ($room->fresh()->dubGame->state === DubGameState::Recording && $guard++ < 12) {
            $game = $room->fresh()->dubGame;
            $line = $clip->lines->firstWhere('position', $game->current_line_index);
            $ownerId = $game->roleAssignments()
                ->where('dub_clip_character_id', $line->dub_clip_character_id)
                ->value('room_player_id');
            $service->submitTake($room->fresh(), $ownerId === $p1->id ? $p1 : $p2, $line, "dub/{$line->id}.webm", 900);
        }

        $service->onAssemblyComplete($room->fresh()->dubGame, 'dub/games/x/round-1.mp4');
        $service->markWatched($room->fresh());
        $service->submitRating($room->fresh(), $p1, 4);
        $service->submitRating($room->fresh(), $p2, 5);
        $service->finish($room->fresh());

        $this->getJson("/api/dub-rooms/{$room->code}")
            ->assertOk()
            ->assertJsonPath('state', 'finished')
            ->assertJsonPath('total_score', 4.5);
    }
}
