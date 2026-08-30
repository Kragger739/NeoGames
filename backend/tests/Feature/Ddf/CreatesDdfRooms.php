<?php

namespace Tests\Feature\Ddf;

use App\Models\DdfQuestion;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\User;

/** Shared setup for DDF feature tests - a room+DdfGame row, active players with hearts, and pool questions. */
trait CreatesDdfRooms
{
    private function createDdfRoom(array $gameAttributes = []): GameRoom
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['game' => 'ddf']);
        $room->ddfGame()->create(array_merge(['state' => 'lobby'], $gameAttributes));

        return $room->fresh();
    }

    private function addActivePlayer(GameRoom $room, array $stateAttributes = []): RoomPlayer
    {
        $player = RoomPlayer::factory()->for($room, 'room')->create();
        $player->ddfState()->create(array_merge(['hearts' => 3, 'is_camera_ready' => true], $stateAttributes));

        return $player->fresh();
    }

    private function seedQuestions(int $count = 5, string $language = 'en'): void
    {
        for ($i = 0; $i < $count; $i++) {
            DdfQuestion::create([
                'category' => 'history',
                'language' => $language,
                'text' => "Test question {$i} ".uniqid(),
                'correct_answer' => 'answer',
            ]);
        }
    }
}
