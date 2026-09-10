<?php

namespace Tests\Feature\Dub;

use App\Enums\DubGameState;
use App\Jobs\AdvanceDubState;
use App\Jobs\AssembleDubVideo;
use App\Models\Dataset;
use App\Models\DubClip;
use App\Models\GameRoom;
use App\Models\User;
use App\Services\DubGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DubPackGameTest extends TestCase
{
    use CreatesDubRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake([AdvanceDubState::class, AssembleDubVideo::class]);
    }

    private function approvedPack(User $owner, int $clips = 2): Dataset
    {
        $pack = $owner->datasets()->create([
            'name' => 'Approved pack', 'type' => 'dub', 'visibility' => 'public', 'review_status' => 'approved',
        ]);

        for ($i = 0; $i < $clips; $i++) {
            $this->makeReadyClip(characters: 2, lines: 2, overrides: [
                'source' => 'workshop',
                'dataset_id' => $pack->id,
                'position' => $i,
                'title' => "Pack clip {$i}",
            ]);
        }

        return $pack;
    }

    public function test_creating_a_room_with_an_approved_pack_binds_the_dataset(): void
    {
        $owner = User::factory()->create();
        $pack = $this->approvedPack($owner);
        $player = User::factory()->create();

        $code = $this->actingAs($player)->postJson('/api/dub-rooms', [
            'player_mode' => 'solo',
            'dataset_id' => $pack->id,
        ])->assertCreated()->assertJsonPath('pack_name', 'Approved pack')->json('code');

        $room = GameRoom::where('code', $code)->firstOrFail();
        $this->assertSame($pack->id, $room->dataset_id);
    }

    public function test_a_pack_room_plays_its_clips_as_sequential_rounds_then_finishes(): void
    {
        $owner = User::factory()->create();
        $pack = $this->approvedPack($owner, clips: 2);
        $room = $this->createDubRoom(roomAttributes: ['player_mode' => 'solo', 'dataset_id' => $pack->id]);
        $solo = $this->seatHost($room, ['mic_ready' => true]);
        $service = app(DubGameService::class);

        $service->start($room->fresh());

        $game = $room->fresh()->dubGame;
        $firstClipId = $game->dub_clip_id;
        $this->assertNotNull($firstClipId);
        $this->assertSame(DubGameState::Recording, $game->state);

        // Round 1.
        $this->recordEveryLine($room, $solo);
        $service->onAssemblyComplete($room->fresh()->dubGame, 'dub/games/x/r1.mp4');
        $service->markWatched($room->fresh()); // solo -> round_complete
        $service->nextRound($room->fresh()); // pack: auto-advance

        $game = $room->fresh()->dubGame;
        $this->assertSame(2, $game->round_number);
        $this->assertNotSame($firstClipId, $game->dub_clip_id);
        $this->assertContains($firstClipId, $game->played_clip_ids);

        // Round 2 (last clip) -> nextRound finishes the game.
        $this->recordEveryLine($room, $solo);
        $service->onAssemblyComplete($room->fresh()->dubGame, 'dub/games/x/r2.mp4');
        $service->markWatched($room->fresh());
        $service->nextRound($room->fresh());

        $this->assertSame(DubGameState::Finished, $room->fresh()->dubGame->state);
    }

    public function test_another_users_unapproved_pack_is_rejected_but_the_owner_can_playtest(): void
    {
        $owner = User::factory()->create();
        $pack = $owner->datasets()->create([
            'name' => 'Pending pack', 'type' => 'dub', 'visibility' => 'public', 'review_status' => 'pending',
        ]);
        $this->makeReadyClip(2, 2, ['source' => 'workshop', 'dataset_id' => $pack->id, 'position' => 0]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/dub-rooms', ['dataset_id' => $pack->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dataset_id');

        $this->actingAs($owner)
            ->postJson('/api/dub-rooms', ['player_mode' => 'solo', 'dataset_id' => $pack->id])
            ->assertCreated();
    }

    public function test_a_pack_with_no_ready_clips_is_rejected(): void
    {
        $owner = User::factory()->create();
        $pack = $owner->datasets()->create([
            'name' => 'Empty', 'type' => 'dub', 'visibility' => 'public', 'review_status' => 'approved',
        ]);
        DubClip::create(['source' => 'workshop', 'dataset_id' => $pack->id, 'title' => 'draft', 'status' => 'review', 'position' => 0]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/dub-rooms', ['dataset_id' => $pack->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dataset_id');
    }

    private function recordEveryLine(GameRoom $room, $solo): void
    {
        $service = app(DubGameService::class);
        $guard = 0;
        while ($room->fresh()->dubGame->state === DubGameState::Recording && $guard++ < 12) {
            $game = $room->fresh()->dubGame;
            $line = DubClip::find($game->dub_clip_id)->lines()->where('position', $game->current_line_index)->firstOrFail();
            $service->submitTake($room->fresh(), $solo, $line, "dub/{$line->id}.webm", 900);
        }
    }
}
