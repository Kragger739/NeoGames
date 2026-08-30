<?php

namespace Database\Factories;

use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Song>
 */
class SongFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_track_id' => fake()->unique()->bothify('????????????????????##'),
            'title' => fake()->sentence(3),
            'artist' => fake()->name(),
            'preview_url' => 'https://example.com/preview/'.fake()->uuid().'.mp3',
            'album_art_url' => null,
            'popularity' => fake()->numberBetween(20, 100),
            'release_year' => fake()->numberBetween(2000, 2024),
        ];
    }

    /**
     * Set popularity to a value within the given tier's range.
     */
    public function forTier(\App\Enums\DifficultyTier $tier): static
    {
        [$min, $max] = $tier->popularityRange();

        return $this->state(fn () => ['popularity' => fake()->numberBetween($min, $max)]);
    }
}
