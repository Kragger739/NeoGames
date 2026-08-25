<?php

namespace Database\Factories;

use App\Models\Guess;
use App\Models\Round;
use App\Models\RoomPlayer;
use App\Support\SnippetStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guess>
 */
class GuessFactory extends Factory
{
    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'player_id' => RoomPlayer::factory(),
            'guess_text' => fake()->word(),
            'correct' => false,
            'snippet_stage_at_guess' => SnippetStage::first(),
        ];
    }
}
