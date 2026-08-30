<?php

namespace Tests\Feature\Song;

use App\Enums\DifficultyTier;
use App\Enums\SongGenre;
use App\Models\Song;
use App\Services\SongDiscoveryService;
use App\Support\SongFilter;
use App\Support\SongSelectionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The song pool is seeded out of band by `songs:sync` (see
 * SyncSongsCommandTest); round-time selection reads it directly with no
 * third-party calls. These tests exercise that selection engine against
 * factory-seeded rows.
 */
class SongDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): SongDiscoveryService
    {
        return app(SongDiscoveryService::class);
    }

    public function test_it_picks_a_cached_song_within_the_tiers_band_without_any_http(): void
    {
        Http::fake();
        Song::factory()->forTier(DifficultyTier::Hard)->create();

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Hard));

        $this->assertNotNull($song);
        [$min, $max] = DifficultyTier::Hard->popularityRange();
        $this->assertGreaterThanOrEqual($min, $song->popularity);
        $this->assertLessThanOrEqual($max, $song->popularity);
        Http::assertNothingSent();
    }

    public function test_it_excludes_already_used_tracks(): void
    {
        $used = Song::factory()->forTier(DifficultyTier::Easy)->create();
        $fresh = Song::factory()->forTier(DifficultyTier::Easy)->create();

        $song = $this->svc()->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy),
            new SongSelectionContext(excludeTrackIds: [$used->provider_track_id]),
        );

        $this->assertSame($fresh->id, $song->id);
    }

    public function test_it_prefers_a_never_used_song_over_one_already_played(): void
    {
        $played = Song::factory()->forTier(DifficultyTier::Easy)->create(['last_used_at' => now()->subMinute()]);
        $neverPlayed = Song::factory()->forTier(DifficultyTier::Easy)->create(['last_used_at' => null]);

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Easy));

        $this->assertSame($neverPlayed->id, $song->id);
        $this->assertNotSame($played->id, $song->id);
    }

    public function test_it_cycles_to_the_stalest_song_once_everything_has_been_played(): void
    {
        $recent = Song::factory()->forTier(DifficultyTier::Easy)->create(['last_used_at' => now()->subMinute()]);
        $stale = Song::factory()->forTier(DifficultyTier::Easy)->create(['last_used_at' => now()->subDay()]);

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Easy));

        $this->assertSame($stale->id, $song->id);
        $this->assertNotSame($recent->id, $song->id);
    }

    public function test_it_returns_null_when_the_pool_has_nothing_matching(): void
    {
        Http::fake();

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Hard));

        $this->assertNull($song);
        Http::assertNothingSent();
    }

    public function test_when_the_tiers_band_is_empty_it_falls_back_to_the_closest_cached_song(): void
    {
        $offBand = Song::factory()->forTier(DifficultyTier::Medium)->create();

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Hard));

        $this->assertSame($offBand->id, $song->id);
    }

    public function test_pop_filter_only_ever_picks_pop_tagged_songs(): void
    {
        $pop = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'pop']);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'hip_hop']);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => null]);

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Easy, SongGenre::Pop));

        $this->assertSame($pop->id, $song->id);
    }

    public function test_hip_hop_filter_only_ever_picks_hip_hop_tagged_songs(): void
    {
        $hh = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'hip_hop']);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'pop']);

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Easy, SongGenre::HipHop));

        $this->assertSame($hh->id, $song->id);
    }

    public function test_german_rap_filter_only_ever_picks_german_rap_tagged_songs(): void
    {
        $gr = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'german_rap']);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => null]);

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Easy, SongGenre::GermanRap));

        $this->assertSame($gr->id, $song->id);
    }

    public function test_artist_filter_picks_only_that_artists_songs_case_insensitively(): void
    {
        $match = Song::factory()->forTier(DifficultyTier::Easy)->create(['artist' => 'The Beatles', 'release_year' => 1968]);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['artist' => 'Someone Else', 'release_year' => 1968]);

        $song = $this->svc()->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Artist, artistName: 'the beatles'),
        );

        $this->assertSame($match->id, $song->id);
    }

    public function test_multi_artist_filter_matches_any_of_the_named_artists(): void
    {
        $a = Song::factory()->create(['artist' => 'ABBA', 'release_year' => 1976, 'popularity' => 60]);
        $b = Song::factory()->create(['artist' => 'Queen', 'release_year' => 1975, 'popularity' => 70]);
        Song::factory()->create(['artist' => 'Nobody', 'release_year' => 1975, 'popularity' => 70]);

        $song = $this->svc()->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::MultiArtist, artistNames: ['abba', 'queen']),
        );

        $this->assertContains($song->id, [$a->id, $b->id]);
    }

    public function test_relative_tier_bucket_gives_the_remainder_to_the_easiest_tiers_first(): void
    {
        // 7 songs, 5 enabled tiers -> bucket sizes [2, 2, 1, 1, 1] by
        // popularity desc; Medium (index 2) should get the 5th-ranked song.
        $pops = [95, 90, 80, 70, 60, 50, 40];
        $songs = collect($pops)->map(fn ($p) => Song::factory()->create([
            'artist' => 'One Act', 'release_year' => 2010, 'popularity' => $p,
        ]));

        $filter = new SongFilter(
            DifficultyTier::Medium,
            SongGenre::Artist,
            artistName: 'One Act',
            enabledTiers: DifficultyTier::ordered(),
        );

        $song = $this->svc()->findRandomSongForTier($filter);

        $this->assertSame($songs[4]->id, $song->id);
    }

    public function test_normal_mode_never_picks_a_song_older_than_2000(): void
    {
        Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 1985]);

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Easy));

        $this->assertNull($song);
    }

    public function test_classics_filter_can_pick_a_pre_2000_song(): void
    {
        $classic = Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 1975]);

        $song = $this->svc()->findRandomSongForTier(new SongFilter(DifficultyTier::Easy, SongGenre::Classics));

        $this->assertSame($classic->id, $song->id);
    }

    public function test_year_filter_only_picks_songs_inside_the_requested_range(): void
    {
        $inside = Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 1995]);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 2005]);

        $song = $this->svc()->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Year, yearFrom: 1990, yearTo: 1999),
        );

        $this->assertSame($inside->id, $song->id);
    }

    public function test_session_context_avoids_repeating_an_artist_already_used_this_game(): void
    {
        $used = Song::factory()->forTier(DifficultyTier::Easy)->create(['artist_provider_id' => 'artist-27']);
        $other = Song::factory()->forTier(DifficultyTier::Easy)->create(['artist_provider_id' => 'artist-99']);

        $song = $this->svc()->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy),
            new SongSelectionContext(usedArtistProviderIds: ['artist-27'], eraCounts: ['mainstream' => 1]),
        );

        $this->assertSame($other->id, $song->id);
        $this->assertNotSame($used->id, $song->id);
    }

    public function test_an_exceptionally_popular_artist_can_repeat_when_every_other_option_is_exhausted(): void
    {
        $onlyOption = Song::factory()->create([
            'artist_provider_id' => 'artist-27',
            'popularity' => 95,
            'release_year' => 2007,
            'last_used_at' => now()->subHour(),
        ]);

        $song = $this->svc()->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy),
            new SongSelectionContext(usedArtistProviderIds: ['artist-27'], eraCounts: ['mainstream' => 1]),
        );

        $this->assertSame($onlyOption->id, $song->id);
    }

    public function test_ensure_playable_is_true_with_a_preview_url_and_false_when_blank(): void
    {
        $ok = Song::factory()->create(['preview_url' => 'https://audio-ssl.itunes.apple.com/x.m4a']);
        $blank = Song::factory()->create(['preview_url' => '']);

        $this->assertTrue($this->svc()->ensurePlayable($ok));
        $this->assertFalse($this->svc()->ensurePlayable($blank));
    }

    public function test_ensure_follower_count_uses_the_seeded_value_without_http(): void
    {
        Http::fake();
        $song = Song::factory()->create(['artist_follower_count' => 4_200_000]);

        $this->assertSame(4_200_000, $this->svc()->ensureFollowerCount($song));
        Http::assertNothingSent();
    }

    public function test_ensure_follower_count_fetches_from_spotify_when_missing_and_caches_it(): void
    {
        config(['services.spotify.client_id' => 'id', 'services.spotify.client_secret' => 'secret']);
        Http::fake([
            'accounts.spotify.com/api/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.spotify.com/v1/artists/*' => Http::response(['id' => 'sp-1', 'followers' => ['total' => 5_194_479]]),
        ]);

        $song = Song::factory()->create(['artist_provider_id' => 'sp-1', 'artist_follower_count' => null]);

        $this->assertSame(5_194_479, $this->svc()->ensureFollowerCount($song));
        $this->assertSame(5_194_479, $song->fresh()->artist_follower_count);
    }

    public function test_ensure_follower_count_is_null_when_the_row_has_no_artist_id(): void
    {
        Http::fake();
        $song = Song::factory()->create(['artist_provider_id' => null, 'artist_follower_count' => null]);

        $this->assertNull($this->svc()->ensureFollowerCount($song));
        Http::assertNothingSent();
    }
}
