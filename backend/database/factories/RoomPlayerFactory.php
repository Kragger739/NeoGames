<?php

namespace Database\Factories;

use App\Models\GameRoom;
use App\Models\RoomPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomPlayer>
 */
class RoomPlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_id' => GameRoom::factory(),
            'nickname' => fake()->firstName(),
            'connection_token' => RoomPlayer::generateConnectionToken(),
            'score' => 0,
        ];
    }
}
