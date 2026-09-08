<?php

namespace Database\Factories;

use App\Models\IconicArtist;
use App\Models\IconicArtistSong;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IconicArtist>
 */
class IconicArtistFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            'image_path' => null,
            'enabled' => true,
            'sort_order' => 0,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    /**
     * A fully-fetched artist: `$count` playable iconic_artist_songs, ranked
     * 1..$count by descending popularity, each backed by a real Song row.
     */
    public function fetched(int $count = 40): static
    {
        return $this->state(fn () => [
            'fetch_status' => 'done',
            'fetched_total' => $count,
            'fetched_playable' => $count,
            'fetched_at' => now(),
        ])->afterCreating(function (IconicArtist $artist) use ($count) {
            for ($rank = 1; $rank <= $count; $rank++) {
                $song = Song::factory()->create([
                    'artist' => $artist->name,
                    'popularity' => max(1, 101 - $rank * 2),
                ]);

                IconicArtistSong::factory()->create([
                    'iconic_artist_id' => $artist->id,
                    'song_id' => $song->id,
                    'provider_track_id' => $song->provider_track_id,
                    'title' => $song->title,
                    'artist' => $song->artist,
                    'popularity' => $song->popularity,
                    'rank' => $rank,
                    'preview_url' => $song->preview_url,
                    'unplayable' => false,
                ]);
            }
        });
    }
}
