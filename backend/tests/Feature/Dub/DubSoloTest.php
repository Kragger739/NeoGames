<?php

namespace Tests\Feature\Dub;

use App\Enums\DubGameState;
use App\Jobs\AdvanceDubState;
use App\Jobs\AssembleDubVideo;
use App\Models\GameRoom;
use App\Models\User;
use App\Services\DubGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DubSoloTest extends TestCase
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

    public function test_creating_a_solo_room_sets_the_mode_and_zeroes_the_timers(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/dub-rooms', [
            'player_mode' => 'solo',
            'line_timer_seconds' => 30,
            'watch_timer_seconds' => 20,
        ])->assertCreated()->assertJsonPath('player_mode', 'solo');

        $room = GameRoom::where('code', $response->json('code'))->firstOrFail();
        $this->assertSame('solo', $room->player_mode->value);
        $this->assertSame(0, $room->dubGame->line_timer_seconds);
        $this->assertSame(0, $room->dubGame->watch_timer_seconds);
    }

    public function test_a_solo_room_rejects_a_second_player(): void
    {
        $room = $this->createDubRoom(roomAttributes: ['player_mode' => 'solo']);
        $this->seatHost($room);

        $this->postJson("/api/rooms/{$room->code}/join", ['nickname' => 'Nope'])
            ->assertStatus(422);
    }

    public function test_solo_start_assigns_every_character_to_the_one_player_and_goes_straight_to_recording(): void
    {
        $room = $this->createDubRoom(roomAttributes: ['player_mode' => 'solo']);
        $clip = $this->makeReadyClip(characters: 3, lines: 6);
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $solo = $this->seatHost($room, ['mic_ready' => true]);

        $this->service->start($room->fresh());

        $game = $room->fresh()->dubGame;
        $this->assertSame(DubGameState::Recording, $game->state);
        $this->assertSame(0, $game->current_line_index);
        $this->assertSame(3, $game->roleAssignments()->where('round_number', 1)->count());
        $this->assertSame(0, $game->roleAssignments()->whereNull('room_player_id')->count());
        $this->assertSame(3, $game->roleAssignments()->where('room_player_id', $solo->id)->count());
    }

    public function test_solo_start_still_needs_a_mic_ready_player(): void
    {
        $room = $this->createDubRoom(roomAttributes: ['player_mode' => 'solo']);
        $clip = $this->makeReadyClip();
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $this->seatHost($room, ['mic_ready' => false]);

        $this->expectException(ValidationException::class);
        $this->service->start($room->fresh());
    }

    public function test_solo_skips_rating_and_finishes_with_no_score(): void
    {
        $room = $this->createDubRoom(roomAttributes: ['player_mode' => 'solo']);
        $clip = $this->makeReadyClip(characters: 2, lines: 4);
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $solo = $this->seatHost($room, ['mic_ready' => true]);

        $this->service->start($room->fresh());
        $clip = $clip->fresh()->load('lines');

        $guard = 0;
        while ($room->fresh()->dubGame->state === DubGameState::Recording && $guard++ < 12) {
            $game = $room->fresh()->dubGame;
            $line = $clip->lines->firstWhere('position', $game->current_line_index);
            $this->service->submitTake($room->fresh(), $solo, $line, "dub/{$line->id}.webm", 900);
        }

        $this->assertSame(DubGameState::Assembling, $room->fresh()->dubGame->state);

        $this->service->onAssemblyComplete($room->fresh()->dubGame, 'dub/games/x/round-1.mp4');
        $this->assertSame(DubGameState::Watch, $room->fresh()->dubGame->state);

        // Watch -> straight to RoundComplete, no Rating.
        $this->service->markWatched($room->fresh());
        $game = $room->fresh()->dubGame;
        $this->assertSame(DubGameState::RoundComplete, $game->state);
        $this->assertNull($game->last_round_score);
        $this->assertEqualsWithDelta(0.0, $game->total_score, 0.001);
        $this->assertSame(0, $game->ratings()->count());

        $this->service->finish($room->fresh());
        $this->assertSame(DubGameState::Finished, $room->fresh()->dubGame->state);
    }

    public function test_multiplayer_is_unaffected_and_still_needs_two_players(): void
    {
        $room = $this->createDubRoom();
        $clip = $this->makeReadyClip();
        $room->dubGame->update(['dub_clip_id' => $clip->id]);
        $this->seatHost($room, ['mic_ready' => true]);

        $this->expectException(ValidationException::class);
        $this->service->start($room->fresh());
    }
}
