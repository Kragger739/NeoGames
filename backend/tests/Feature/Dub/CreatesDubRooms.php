<?php

namespace Tests\Feature\Dub;

use App\Models\DubClip;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\User;

/**
 * Shared setup for "Dub Together" feature tests - a room + DubGame row,
 * seated players with a mic-ready flag, and a fully prepared clip
 * (characters + timed lines) built directly, with no media sidecar.
 */
trait CreatesDubRooms
{
    private function createDubRoom(array $gameAttributes = []): GameRoom
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['game' => 'dub']);
        $room->dubGame()->create(array_merge(['state' => 'lobby'], $gameAttributes));

        return $room->fresh();
    }

    private function addPlayer(GameRoom $room, array $stateAttributes = []): RoomPlayer
    {
        $player = RoomPlayer::factory()->for($room, 'room')->create();
        $player->dubState()->create(array_merge(['mic_ready' => true], $stateAttributes));

        return $player->fresh();
    }

    /** Seat the host's own account as a player, the way DubGameController::store() does. */
    private function seatHost(GameRoom $room, array $stateAttributes = []): RoomPlayer
    {
        $player = RoomPlayer::factory()->for($room, 'room')->create([
            'user_id' => $room->host_id,
            'nickname' => $room->host->name,
        ]);
        $player->dubState()->create(array_merge(['mic_ready' => true], $stateAttributes));

        return $player->fresh();
    }

    private function makeReadyClip(int $characters = 2, int $lines = 6): DubClip
    {
        $clip = DubClip::create([
            'source' => 'library',
            'title' => 'Test clip '.uniqid(),
            'status' => 'ready',
            'is_public' => true,
            'source_video_path' => 'dub/clips/test/source.mp4',
            'music_bed_path' => 'dub/clips/test/music-bed.m4a',
            'duration_ms' => $lines * 2000,
        ]);

        $characterRows = [];
        for ($c = 0; $c < $characters; $c++) {
            $characterRows[] = $clip->characters()->create([
                'key' => "SPEAKER_0{$c}",
                'display_name' => 'Character '.($c + 1),
                'color' => ['grape', 'turquoise', 'coral', 'sunflower', 'bubblegum'][$c % 5],
                'position' => $c,
            ]);
        }

        for ($l = 0; $l < $lines; $l++) {
            $clip->lines()->create([
                'dub_clip_character_id' => $characterRows[$l % $characters]->id,
                'position' => $l,
                'start_ms' => $l * 2000,
                'end_ms' => $l * 2000 + 1500,
                'text' => 'Line '.($l + 1),
            ]);
        }

        return $clip->fresh();
    }
}
