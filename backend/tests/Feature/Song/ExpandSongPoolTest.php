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

class ExpandSongPoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_round_dispatches_expand_song_pool_for_the_current_tier(): void
    {
        Event::fake();
        Queue::fake([ExpandSongPool::class]);
        $this->fakeDeezerTrackRefresh();

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->create();
        }
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);

        app(RoundService::class)->start($room);

        Queue::assertPushed(ExpandSongPool::class, fn (ExpandSongPool $job) => $job->filter->tier === DifficultyTier::Easy);
    }

    public function test_it_no_ops_when_the_pool_is_already_healthy(): void
    {
        Http::fake(); // any call at all fails the test via assertNothingSent below.

        Song::factory()->forTier(DifficultyTier::Easy)->count(25)->create();

        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy)))->handle(app(SongDiscoveryService::class));

        Http::assertNothingSent();
    }

    public function test_it_tops_up_the_pool_when_below_the_threshold(): void
    {
        Http::fake([
            'api.deezer.com/chart/0/tracks*' => Http::response([
                // ~950,000 rank -> popularity ~95, inside Easy's [85,100] range.
                'data' => [$this->fakeDeezerTrack('new-track', 'New Track', rank: 95_000)],
            ], 200),
            'api.deezer.com/track/new-track' => Http::response(
                $this->fakeDeezerTrackDetails('new-track', 'New Track'),
                200,
            ),
        ]);

        Song::factory()->forTier(DifficultyTier::Easy)->count(3)->create();

        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy)))->handle(app(SongDiscoveryService::class));

        $this->assertDatabaseHas('songs', ['deezer_track_id' => 'new-track']);
        Http::assertSentCount(8); // MAX_DISCOVERY_ATTEMPTS (4) x 2 calls (chart + trackDetails) each attempt
    }

    public function test_a_second_rapid_run_makes_no_further_http_calls_because_of_the_lock_cooldown(): void
    {
        Http::fake([
            'api.deezer.com/chart/0/tracks*' => Http::response([
                'data' => [$this->fakeDeezerTrack('new-track', 'New Track', rank: 95_000)],
            ], 200),
            'api.deezer.com/track/new-track' => Http::response(
                $this->fakeDeezerTrackDetails('new-track', 'New Track'),
                200,
            ),
        ]);

        Song::factory()->forTier(DifficultyTier::Easy)->count(3)->create();

        $songDiscovery = app(SongDiscoveryService::class);
        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy)))->handle($songDiscovery);
        $firstRunCalls = count(Http::recorded());

        $this->assertGreaterThan(0, $firstRunCalls);

        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy)))->handle($songDiscovery);

        $this->assertSame($firstRunCalls, count(Http::recorded()));
    }

    public function test_a_pop_filtered_run_does_not_share_a_lock_with_a_same_tier_normal_run(): void
    {
        Http::fake([
            'api.deezer.com/chart/0/tracks*' => Http::response([
                'data' => [$this->fakeDeezerTrack('normal-track', 'Normal Track', rank: 95_000)],
            ], 200),
            'api.deezer.com/chart/132/tracks*' => Http::response([
                'data' => [$this->fakeDeezerTrack('pop-track', 'Pop Track', rank: 95_000)],
            ], 200),
            'api.deezer.com/track/normal-track' => Http::response(
                $this->fakeDeezerTrackDetails('normal-track', 'Normal Track'),
                200,
            ),
            'api.deezer.com/track/pop-track' => Http::response(
                $this->fakeDeezerTrackDetails('pop-track', 'Pop Track'),
                200,
            ),
        ]);

        Song::factory()->forTier(DifficultyTier::Easy)->count(3)->create();

        $songDiscovery = app(SongDiscoveryService::class);
        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy)))->handle($songDiscovery);
        $callsAfterNormalRun = count(Http::recorded());

        $this->assertGreaterThan(0, $callsAfterNormalRun);

        // A Pop-filtered run for the same tier must not be blocked by the
        // Normal run's cooldown - it's a genuinely different pool/lock key.
        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy, SongGenre::Pop)))->handle($songDiscovery);

        $this->assertGreaterThan($callsAfterNormalRun, count(Http::recorded()));
    }

    /**
     * matchingFilter()'s global popularity band is meaningless for Artist/
     * MultiArtist (they rank relatively - see SongDiscoveryService::
     * relativeTierBucket()), so the health check must be skipped for them
     * and ensureArtistPoolReady() called directly instead of the chart/
     * word-search discoverAndCache() loop.
     */
    public function test_artist_genre_bypasses_the_global_band_health_check_and_uses_artist_top_tracks(): void
    {
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response([
                'data' => [['id' => 555, 'name' => 'Real Artist', 'nb_fan' => 1_000_000]],
            ], 200),
            'api.deezer.com/artist/555/top*' => Http::response([
                'data' => [$this->fakeDeezerTrack('artist-song', 'Some Song', rank: 40_000)],
            ], 200),
            'api.deezer.com/track/artist-song' => Http::response(
                $this->fakeDeezerTrackDetails('artist-song', 'Some Song'),
                200,
            ),
        ]);

        (new ExpandSongPool(new SongFilter(DifficultyTier::Easy, SongGenre::Artist, artistName: 'Real Artist')))
            ->handle(app(SongDiscoveryService::class));

        $this->assertDatabaseHas('songs', ['deezer_track_id' => 'artist-song']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/chart/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/search?'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeDeezerTrack(string $id, string $name, int $rank = 500_000): array
    {
        return [
            'id' => $id,
            'title' => $name,
            'artist' => ['name' => 'Some Artist'],
            'album' => ['cover_medium' => 'https://example.com/art.jpg'],
            'preview' => 'https://example.com/preview.mp3',
            'rank' => $rank,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeDeezerTrackDetails(string $id, string $name, int $rank = 500_000): array
    {
        return [
            'id' => $id,
            'title' => $name,
            'artist' => ['name' => 'Some Artist'],
            'album' => ['cover_medium' => 'https://example.com/art.jpg'],
            'preview' => 'https://example.com/preview.mp3',
            'rank' => $rank,
            'release_date' => '2015-06-09',
        ];
    }
}
