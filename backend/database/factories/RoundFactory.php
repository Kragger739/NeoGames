<?php

namespace Database\Factories;

use App\Enums\DifficultyTier;
use App\Models\GameRoom;
use App\Models\Round;
use App\Models\Song;
use App\Support\SnippetStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Round>
 */
class RoundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_id' => GameRoom::factory(),
            'song_id' => Song::factory(),
            'tier' => DifficultyTier::Easy->value,
            'snippet_stage' => SnippetStage::first(),
            'stage_started_at' => now(),
            'status' => 'playing',
            'winning_player_id' => null,
            'stage_version' => 1,
        ];
    }
}
