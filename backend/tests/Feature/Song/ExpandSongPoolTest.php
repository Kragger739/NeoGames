<?php

namespace Tests\Feature\Song;

use App\Enums\DifficultyTier;
use App\Enums\SongGenre;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\Song;
use App\Models\User;
use App\Services\RoundService;
use App\Services\SongDiscoveryService;
use App\Support\SongFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The fixed-playlist genres' pool is owned by `songs:sync` now, so
 * ExpandSongPool only does real work for Artist / MultiArtist rooms (warming
 * the per-room pool from the named act's Spotify top tracks).
 */
class ExpandSongPoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_round_dispatches_expand_song_pool_for_the_current_tier(): void
    {
        Event::fake();
        Queue::fake([ExpandSongPool::class]);

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->create();
        }
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);

        app(RoundService::class)->start($room);

        Queue::assertPushed(ExpandSongPool::class, fn (ExpandSongPool $job) => $job->filter->tier === DifficultyTier::Easy);
    }

    public function test_it_is_a_no_op_for_a_fixed_playlist_genre(): void
    {
        Http::fake(); // any call at all fails the test via assertNothingSent below.

        Song::factory()->forTier(DifficultyTier::Easy)->count(3)->create();

        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy)))->handle(app(SongDiscoveryService::class));
        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy, SongGenre::Pop)))->handle(app(SongDiscoveryService::class));

        Http::assertNothingSent();
    }

    public function test_artist_genre_warms_the_pool_from_spotify_top_tracks(): void
    {
        $this->fakeSpotifyToken();
        Http::fake([
            'api.spotify.com/v1/search*' => Http::response([
                'artists' => ['items' => [
                    ['id' => 'sp-real', 'name' => 'Real Artist', 'images' => [], 'followers' => ['total' => 1_000_000]],
                ]],
            ], 200),
            'api.spotify.com/v1/artists/sp-real/top-tracks*' => Http::response([
                'tracks' => [[
                    'id' => 'sp-song',
                    'name' => 'Some Song',
                    'popularity' => 55,
                    'external_ids' => ['isrc' => 'X'],
                    'artists' => [['id' => 'sp-real', 'name' => 'Real Artist']],
                    'album' => ['release_date' => '2015-06-09', 'images' => []],
                ]],
            ], 200),
            'api.spotify.com/v1/artists/sp-real' => Http::response(['id' => 'sp-real', 'followers' => ['total' => 1_000_000]], 200),
            'itunes.apple.com/search*' => Http::response(['results' => [[
                'kind' => 'song', 'trackId' => 42, 'trackName' => 'Some Song', 'artistName' => 'Real Artist',
                'previewUrl' => 'https://audio-ssl.itunes.apple.com/x.m4a', 'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg',
                'releaseDate' => '2015-06-09T00:00:00Z',
            ]]], 200),
        ]);

        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy, SongGenre::Artist, artistName: 'Real Artist')))
            ->handle(app(SongDiscoveryService::class));

        $this->assertDatabaseHas('songs', ['provider_track_id' => 'sp-song', 'artist' => 'Real Artist']);
    }
}
