<?php

namespace Database\Factories;

use App\Enums\DifficultyTier;
use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameRoom>
 */
class GameRoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => GameRoom::generateUniqueCode(),
            'host_id' => User::factory(),
            'status' => 'lobby',
            'game' => 'guess_the_song',
            // Explicit even though the migrations default these columns -
            // Eloquent's create() never re-fetches the row, so a
            // freshly-created in-memory model would otherwise have these
            // attributes simply absent (not "normal"/"classic") until the
            // next ->fresh()/->refresh(), which breaks anything (like
            // SongFilter's constructor) that requires a real enum instance
            // rather than a null passthrough.
            'mode' => 'classic',
            'genre' => 'normal',
            'songs_per_tier' => 3,
            'guess_timeout_seconds' => 8,
            'current_tier' => DifficultyTier::Easy->value,
            'current_song_index' => 0,
        ];
    }
}
