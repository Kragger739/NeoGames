<?php

namespace Database\Factories;

use App\Models\IconicArtist;
use App\Models\IconicArtistSong;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IconicArtistSong>
 */
class IconicArtistSongFactory extends Factory
{
    public function definition(): array
    {
        return [
            'iconic_artist_id' => IconicArtist::factory(),
            'song_id' => Song::factory(),
            'provider_track_id' => fake()->unique()->bothify('spotify??????????????##'),
            'title' => fake()->sentence(3),
            'artist' => fake()->name(),
            'album_art_url' => null,
            'release_year' => fake()->numberBetween(1960, 2024),
            'popularity' => fake()->numberBetween(1, 100),
            'rank' => fake()->numberBetween(1, 40),
            'preview_url' => 'https://example.com/preview/'.fake()->uuid().'.m4a',
            'unplayable' => false,
        ];
    }

    /** A candidate that hasn't had its preview resolved yet. */
    public function unseeded(): static
    {
        return $this->state(fn () => ['song_id' => null, 'preview_url' => null]);
    }

    public function unplayable(): static
    {
        return $this->state(fn () => ['song_id' => null, 'preview_url' => null, 'unplayable' => true]);
    }
}
