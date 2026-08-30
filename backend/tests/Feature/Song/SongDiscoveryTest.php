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
use ReflectionMethod;
use Tests\TestCase;

class SongDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_picks_a_cached_song_within_the_tiers_popularity_range_without_calling_any_api(): void
    {
        Http::fake(); // any call at all fails the test via assertNothingSent below.

        Song::factory()->forTier(DifficultyTier::Hard)->create();

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Hard));

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

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy),
            new SongSelectionContext(excludeTrackIds: [$used->deezer_track_id]),
        );

        $this->assertSame($fresh->id, $song->id);
    }

    public function test_it_prefers_a_never_used_song_over_one_already_played_in_another_room(): void
    {
        $alreadyPlayed = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'last_used_at' => now()->subMinute(),
        ]);
        $neverPlayed = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'last_used_at' => null,
        ]);

        // No per-room exclusion passed - this simulates a brand new room,
        // which previously always redrew from the same small cached pool.
        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy));

        $this->assertSame($neverPlayed->id, $song->id);
        $this->assertNotSame($alreadyPlayed->id, $song->id);
    }

    public function test_it_cycles_to_the_stalest_song_once_everything_in_range_has_been_played(): void
    {
        $recent = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'last_used_at' => now()->subMinute(),
        ]);
        $stale = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'last_used_at' => now()->subDay(),
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy));

        $this->assertSame($stale->id, $song->id);
        $this->assertNotSame($recent->id, $song->id);
    }

    public function test_it_falls_back_to_a_live_search_and_caches_matching_results(): void
    {
        Http::fake([
            'api.deezer.com/search*' => Http::response([
                'data' => [
                    // No preview - should be skipped before ever reaching trackDetails.
                    $this->fakeDeezerTrack('no-preview', 'No Preview', previewUrl: null),
                    // Has a preview, but rank puts it well above Extreme's ceiling.
                    $this->fakeDeezerTrack('too-popular', 'Too Popular', previewUrl: 'https://example.com/a.mp3', rank: 90_000),
                    // Has a preview and matches Extreme's range - should be cached.
                    $this->fakeDeezerTrack('good-match', 'Good Match', previewUrl: 'https://example.com/b.mp3', rank: 40_000),
                ],
            ], 200),
            'api.deezer.com/track/good-match' => Http::response(
                $this->fakeDeezerTrackDetails('good-match', 'Good Match'),
                200,
            ),
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Extreme));

        $this->assertNotNull($song);
        $this->assertSame('good-match', $song->deezer_track_id);
        $this->assertDatabaseHas('songs', ['deezer_track_id' => 'good-match']);
        $this->assertDatabaseMissing('songs', ['deezer_track_id' => 'too-popular']);
        $this->assertDatabaseMissing('songs', ['deezer_track_id' => 'no-preview']);
    }

    public function test_it_skips_karaoke_and_cover_results_even_when_popularity_matches(): void
    {
        Http::fake([
            'api.deezer.com/search*' => Http::response([
                'data' => [
                    $this->fakeDeezerTrack('karaoke-version', 'Good Match (Karaoke Version)', previewUrl: 'https://example.com/a.mp3', rank: 40_000),
                    $this->fakeDeezerTrack('good-match', 'Good Match', previewUrl: 'https://example.com/b.mp3', rank: 40_000),
                ],
            ], 200),
            'api.deezer.com/track/good-match' => Http::response(
                $this->fakeDeezerTrackDetails('good-match', 'Good Match'),
                200,
            ),
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Extreme));

        $this->assertNotNull($song);
        $this->assertSame('good-match', $song->deezer_track_id);
        $this->assertDatabaseMissing('songs', ['deezer_track_id' => 'karaoke-version']);
    }

    public function test_it_sources_easy_intermediate_and_medium_from_the_real_chart(): void
    {
        Http::fake([
            'api.deezer.com/chart/0/tracks*' => Http::response([
                'data' => [
                    // ~950,000 rank -> popularity ~95, inside Easy's range.
                    $this->fakeDeezerTrack('real-hit', 'Real Hit', previewUrl: 'https://example.com/a.mp3', rank: 95_000, artist: 'Famous Artist'),
                    // Same popularity, but a tribute-band cover - must never reach trackDetails.
                    $this->fakeDeezerTrack('tribute-hit', 'Real Hit', previewUrl: 'https://example.com/b.mp3', rank: 95_000, artist: 'Tribute Band'),
                ],
            ], 200),
            'api.deezer.com/track/real-hit' => Http::response(
                $this->fakeDeezerTrackDetails('real-hit', 'Real Hit', artist: 'Famous Artist'),
                200,
            ),
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy));

        $this->assertNotNull($song);
        $this->assertSame('real-hit', $song->deezer_track_id);
        $this->assertDatabaseCount('songs', 1);
    }

    /**
     * Iconic must source ONLY from its seed playlist - no word-search
     * expansion, no other candidates. Faking just the playlist-tracks
     * endpoint (and asserting nothing else was ever called) proves that
     * directly rather than merely checking the picked song looks
     * plausible.
     */
    public function test_iconic_sources_exclusively_from_its_seed_playlists(): void
    {
        $fakes = [];

        foreach (SongGenre::Iconic->deezerPlaylistIds() as $playlistId) {
            $fakes["api.deezer.com/playlist/{$playlistId}/tracks*"] = Http::response(['data' => []], 200);
        }

        $fakes['api.deezer.com/playlist/'.SongGenre::Iconic->deezerPlaylistIds()[0].'/tracks*'] = Http::response([
            'data' => [
                $this->fakeDeezerTrack('playlist-track', 'Playlist Track', previewUrl: 'https://example.com/a.mp3'),
            ],
        ], 200);
        $fakes['api.deezer.com/track/playlist-track'] = Http::response(
            $this->fakeDeezerTrackDetails('playlist-track', 'Playlist Track'),
            200,
        );

        Http::fake($fakes);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Iconic),
        );

        $this->assertNotNull($song);
        $this->assertSame('playlist-track', $song->deezer_track_id);
        $this->assertSame('iconic', $song->genre);
        $this->assertDatabaseCount('songs', 1);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/search'));
    }

    /**
     * A track appearing on more than one of Iconic's ten seed playlists
     * (a real, expected occurrence - "Top Hits 2018"/"2019"/etc. and the
     * all-time list plainly overlap) must only ever end up as one row, not
     * one per playlist it's found on.
     */
    public function test_iconic_deduplicates_a_track_shared_across_multiple_seed_playlists(): void
    {
        $ids = SongGenre::Iconic->deezerPlaylistIds();
        $fakes = [];

        foreach ($ids as $playlistId) {
            $fakes["api.deezer.com/playlist/{$playlistId}/tracks*"] = Http::response(['data' => []], 200);
        }

        $fakes["api.deezer.com/playlist/{$ids[0]}/tracks*"] = Http::response([
            'data' => [
                $this->fakeDeezerTrack('shared-track', 'Shared Track', previewUrl: 'https://example.com/a.mp3'),
                $this->fakeDeezerTrack('only-on-first', 'Only On First', previewUrl: 'https://example.com/b.mp3'),
            ],
        ], 200);
        $fakes["api.deezer.com/playlist/{$ids[1]}/tracks*"] = Http::response([
            'data' => [
                // Same deezer_track_id as above - a real cross-playlist duplicate.
                $this->fakeDeezerTrack('shared-track', 'Shared Track', previewUrl: 'https://example.com/a.mp3'),
                $this->fakeDeezerTrack('only-on-second', 'Only On Second', previewUrl: 'https://example.com/c.mp3'),
            ],
        ], 200);
        $fakes['api.deezer.com/track/shared-track'] = Http::response(
            $this->fakeDeezerTrackDetails('shared-track', 'Shared Track'),
            200,
        );
        $fakes['api.deezer.com/track/only-on-first'] = Http::response(
            $this->fakeDeezerTrackDetails('only-on-first', 'Only On First'),
            200,
        );
        $fakes['api.deezer.com/track/only-on-second'] = Http::response(
            $this->fakeDeezerTrackDetails('only-on-second', 'Only On Second'),
            200,
        );

        Http::fake($fakes);

        app(SongDiscoveryService::class)->topUpTier(new SongFilter(DifficultyTier::Easy, SongGenre::Iconic), attempts: 1);

        // Exactly 3 unique songs, and the shared track was only ever
        // requested via trackDetails() once (Http::fake() records every
        // dispatched request regardless of URL repetition, so this proves
        // the merge deduped it BEFORE processing, not just at the DB layer).
        $this->assertDatabaseCount('songs', 3);
        $sharedTrackRequests = Http::recorded(fn ($request) => str_contains($request->url(), '/track/shared-track'));
        $this->assertCount(1, $sharedTrackRequests);
    }

    /**
     * A cold-cache discovery pass over a large candidate set (Iconic's ten
     * seed playlists, a full 50-track chart) must not fire an unbounded
     * number of Deezer calls - two per surviving candidate (trackDetails +
     * artistFanCount) - or a first-round Start on an empty cache runs the
     * web request straight past its execution-time limit. The pass is
     * capped; ExpandSongPool tops the rest up in the background afterward.
     */
    public function test_a_discovery_pass_caps_how_many_candidates_it_spends_api_calls_on(): void
    {
        $tracks = [];

        for ($i = 0; $i < 40; $i++) {
            $tracks[] = $this->fakeDeezerTrack(
                "hit-{$i}",
                "Hit {$i}",
                previewUrl: "https://example.com/{$i}.mp3",
                rank: 95_000,
                artist: "Artist {$i}",
            );
        }

        Http::fake([
            'api.deezer.com/chart/0/tracks*' => Http::response(['data' => $tracks], 200),
            'api.deezer.com/track/*' => function ($request) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));

                return Http::response([
                    'id' => $id,
                    'title' => 'Hit',
                    'artist' => ['id' => "artist-{$id}", 'name' => "Artist {$id}"],
                    'album' => ['cover_medium' => null],
                    'preview' => 'https://example.com/p.mp3',
                    'rank' => 95_000,
                    'release_date' => '2015-06-09',
                ], 200);
            },
            'api.deezer.com/artist/*' => Http::response(['id' => 'a', 'nb_fan' => 1_000_000], 200),
        ]);

        app(SongDiscoveryService::class)->topUpTier(new SongFilter(DifficultyTier::Easy), attempts: 1);

        $limit = (int) config('songs.discovery_pass_limit');
        $trackDetailCalls = Http::recorded(fn ($request) => str_contains($request->url(), 'api.deezer.com/track/'));

        $this->assertGreaterThan(0, $limit);
        $this->assertLessThanOrEqual($limit, $trackDetailCalls->count());
        $this->assertLessThanOrEqual($limit, Song::count());
    }

    public function test_it_discards_tracks_released_before_2000(): void
    {
        Http::fake([
            'api.deezer.com/search*' => Http::response([
                'data' => [
                    $this->fakeDeezerTrack('too-old', 'Too Old', previewUrl: 'https://example.com/a.mp3', rank: 40_000),
                    $this->fakeDeezerTrack('good-match', 'Good Match', previewUrl: 'https://example.com/b.mp3', rank: 40_000),
                ],
            ], 200),
            'api.deezer.com/track/too-old' => Http::response(
                $this->fakeDeezerTrackDetails('too-old', 'Too Old', releaseDate: '1999-12-31'),
                200,
            ),
            'api.deezer.com/track/good-match' => Http::response(
                $this->fakeDeezerTrackDetails('good-match', 'Good Match', releaseDate: '2000-01-01'),
                200,
            ),
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Extreme));

        $this->assertNotNull($song);
        $this->assertSame('good-match', $song->deezer_track_id);
        $this->assertSame(2000, $song->release_year);
        $this->assertDatabaseMissing('songs', ['deezer_track_id' => 'too-old']);
    }

    public function test_it_discards_tracks_with_no_release_date_at_all(): void
    {
        Http::fake([
            'api.deezer.com/search*' => Http::response([
                'data' => [
                    $this->fakeDeezerTrack('no-date', 'No Date', previewUrl: 'https://example.com/a.mp3', rank: 40_000),
                ],
            ], 200),
            'api.deezer.com/track/no-date' => Http::response(
                $this->fakeDeezerTrackDetails('no-date', 'No Date', releaseDate: null),
                200,
            ),
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Extreme));

        $this->assertNull($song);
        $this->assertDatabaseMissing('songs', ['deezer_track_id' => 'no-date']);
    }

    public function test_a_pre_2000_song_already_in_the_cache_is_never_picked(): void
    {
        Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 1999]);
        $modern = Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 2000]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy));

        $this->assertSame($modern->id, $song->id);
    }

    public function test_it_returns_null_when_no_matching_song_can_be_found_after_retries(): void
    {
        // Hard, not Medium: still on the word-search discovery path, so an
        // empty search response is the only fake this test needs.
        Http::fake([
            'api.deezer.com/search*' => Http::response(['data' => []], 200),
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Hard));

        $this->assertNull($song);
    }

    /**
     * Guaranteed-song fallback: once real discovery has genuinely exhausted
     * every attempt, a cached song that matches genre/year but sits outside
     * the requested tier's own popularity band should still be served
     * rather than leaving the room with nothing to play.
     */
    public function test_when_the_tiers_band_is_empty_it_falls_back_to_the_closest_cached_song(): void
    {
        Http::fake([
            'api.deezer.com/search*' => Http::response(['data' => []], 200),
        ]);

        $offBand = Song::factory()->forTier(DifficultyTier::Medium)->create();

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Hard));

        $this->assertSame($offBand->id, $song->id);
        // Real discovery was genuinely attempted (one search per attempt)
        // before falling back, not skipped straight to the fallback.
        Http::assertSentCount(4);
    }

    public function test_pop_filter_only_ever_picks_pop_tagged_cached_songs(): void
    {
        Http::fake(); // any call at all fails the test via assertNothingSent below.

        $pop = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'pop']);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'hip_hop']);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => null]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Pop),
        );

        $this->assertSame($pop->id, $song->id);
        Http::assertNothingSent();
    }

    public function test_hip_hop_filter_only_ever_picks_hip_hop_tagged_cached_songs(): void
    {
        Http::fake();

        Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'pop']);
        $hipHop = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'hip_hop']);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::HipHop),
        );

        $this->assertSame($hipHop->id, $song->id);
    }

    public function test_german_rap_filter_only_ever_picks_german_rap_tagged_cached_songs(): void
    {
        Http::fake();

        Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'hip_hop']);
        $germanRap = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'german_rap']);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::GermanRap),
        );

        $this->assertSame($germanRap->id, $song->id);
    }

    /**
     * Deezer's global Rap/Hip Hop chart is dominated by English-language
     * content, not German (confirmed live) - German Rap always uses word
     * search instead, even for Easy/Intermediate/Medium, which would
     * otherwise be chart-sourced.
     */
    public function test_german_rap_always_discovers_via_word_search_even_on_chart_sourced_tiers(): void
    {
        Http::fake([
            'api.deezer.com/search*' => function ($request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
                $this->assertContains($query['q'] ?? null, [
                    'capital bra', 'bonez mc', 'raf camora', 'apache 207', 'sido',
                    'kollegah', 'kc rebell', 'summer cem', 'shirin david', 'ufo361',
                    '187 strassenbande', 'luciano', 'kalim', 'ceezy', 'nimo',
                ]);

                return Http::response(['data' => []], 200);
            },
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::GermanRap),
        );

        $this->assertNull($song);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.deezer.com/search'));
    }

    /**
     * Artist genre sources exclusively from the named artist's own top
     * tracks (GET /artist/{id}/top), never a chart - checked even on Easy,
     * which would otherwise be chart-sourced.
     */
    public function test_artist_filter_discovers_only_from_the_named_artists_top_tracks_even_on_a_chart_sourced_tier(): void
    {
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response([
                'data' => [
                    ['id' => 555, 'name' => 'Real Artist', 'nb_fan' => 1_000_000],
                ],
            ], 200),
            'api.deezer.com/artist/555/top*' => Http::response([
                'data' => [
                    $this->fakeDeezerTrack('artist-song', 'Some Song', previewUrl: 'https://example.com/a.mp3', rank: 95_000, artist: 'Real Artist'),
                ],
            ], 200),
            'api.deezer.com/track/artist-song' => Http::response(
                $this->fakeDeezerTrackDetails('artist-song', 'Some Song', artist: 'Real Artist'),
                200,
            ),
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Artist, artistName: 'Real Artist'),
        );

        $this->assertNotNull($song);
        $this->assertSame('artist-song', $song->deezer_track_id);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/chart/'));
    }

    /**
     * Matching happens against the songs.artist column directly, not a
     * genre tag - see SongGenre::cacheTag().
     */
    public function test_artist_filter_only_ever_picks_cached_songs_by_that_artist_case_insensitively(): void
    {
        Http::fake(); // any call at all fails the test via assertNothingSent below.

        $match = Song::factory()->forTier(DifficultyTier::Easy)->create(['artist' => 'real artist']);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['artist' => 'Someone Else']);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Artist, artistName: 'Real Artist'),
        );

        $this->assertSame($match->id, $song->id);
        Http::assertNothingSent();
    }

    /**
     * 7 songs / 5 tiers -> sizes [2,2,1,1,1] (base 1, remainder 2 given to
     * the two easiest buckets first) - popularity descending so rank order
     * is exact, proving tiers rank relative to this room's own pool rather
     * than the global absolute popularity bands.
     */
    public function test_relative_tier_bucket_gives_uneven_split_remainder_to_the_easiest_tiers_first(): void
    {
        Http::fake(); // any call at all fails the test via assertNothingSent below.

        foreach ([100, 99, 98, 97, 96, 95, 94] as $popularity) {
            Song::factory()->create(['artist' => 'Real Artist', 'popularity' => $popularity, 'release_year' => 2020]);
        }

        $easySong = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Artist, artistName: 'Real Artist'),
        );
        $extremeSong = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Extreme, SongGenre::Artist, artistName: 'Real Artist'),
        );

        $this->assertContains($easySong->popularity, [100, 99]);
        $this->assertSame(94, $extremeSong->popularity);
        Http::assertNothingSent();
    }

    /**
     * Guaranteed-song fallback for the relative-ranking path: when every
     * song in a tier's own bucket is already excluded (used this game), it
     * must still serve something rather than leaving the room without a
     * song - same graceful-degrade guarantee the global-band path already
     * has, extended to Artist/MultiArtist's relative bucketing.
     */
    public function test_relative_tier_bucket_falls_back_to_the_closest_cached_song_when_its_own_bucket_is_fully_excluded(): void
    {
        Http::fake([
            'api.deezer.com/search/artist*' => Http::response(['data' => []], 200),
        ]);

        $onlySong = Song::factory()->create(['artist' => 'Real Artist', 'popularity' => 80, 'release_year' => 2020]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Artist, artistName: 'Real Artist'),
            new SongSelectionContext(excludeTrackIds: [$onlySong->deezer_track_id]),
        );

        $this->assertSame($onlySong->id, $song->id);
    }

    /**
     * Extends the single-Artist case-insensitive match to any of several
     * named artists (Part B) - matching against the songs.artist column
     * directly, same as Artist genre.
     */
    public function test_multi_artist_filter_matches_any_of_the_named_artists_case_insensitively(): void
    {
        Http::fake(); // any call at all fails the test via assertNothingSent below.

        $a = Song::factory()->create(['artist' => 'artist one', 'release_year' => 2020, 'popularity' => 80]);
        $b = Song::factory()->create(['artist' => 'Artist Two', 'release_year' => 2020, 'popularity' => 70]);
        Song::factory()->create(['artist' => 'Someone Else', 'release_year' => 2020, 'popularity' => 90]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(
                DifficultyTier::Easy,
                SongGenre::MultiArtist,
                artistNames: ['Artist One', 'artist two'],
                enabledTiers: [DifficultyTier::Easy],
            ),
        );

        $this->assertContains($song->id, [$a->id, $b->id]);
        Http::assertNothingSent();
    }

    /**
     * Same relaxed floor as Classics - a host naming a pre-2000 act must
     * not have their whole catalog filtered out by the normal 2000+ floor.
     */
    public function test_artist_filter_can_pick_a_pre_2000_cached_song(): void
    {
        Http::fake();

        $classic = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'artist' => 'Old Artist',
            'release_year' => 1965,
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Artist, artistName: 'Old Artist'),
        );

        $this->assertSame($classic->id, $song->id);
    }

    public function test_normal_mode_never_picks_a_song_older_than_2000_even_if_cached(): void
    {
        Http::fake();

        Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 1985]);
        $modern = Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 2015]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy));

        $this->assertSame($modern->id, $song->id);
    }

    public function test_classics_filter_can_pick_a_pre_2000_cached_song(): void
    {
        Http::fake();

        $old = Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 1985]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Classics),
        );

        $this->assertSame($old->id, $song->id);
    }

    public function test_classics_filter_rejects_a_song_older_than_its_own_floor(): void
    {
        // Hard, not Easy: word-search discovery, same as
        // test_it_returns_null_when_no_matching_song_can_be_found_after_retries -
        // avoids needing a chart fake for the retry loop.
        Http::fake([
            'api.deezer.com/search*' => Http::response(['data' => []], 200),
        ]);

        Song::factory()->forTier(DifficultyTier::Hard)->create(['release_year' => 1940]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Hard, SongGenre::Classics),
        );

        $this->assertNull($song);
    }

    public function test_year_filter_only_picks_songs_inside_the_requested_range(): void
    {
        Http::fake();

        $inRange = Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 1975]);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 1960]);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => 1995]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Easy, SongGenre::Year, 1970, 1980),
        );

        $this->assertSame($inRange->id, $song->id);
    }

    public function test_discovery_tags_a_previously_untagged_cached_song_without_losing_its_other_fields(): void
    {
        $existing = Song::factory()->forTier(DifficultyTier::Extreme)->create([
            'deezer_track_id' => 'rap-track',
            'title' => 'Rap Track',
            'artist' => 'Some Artist',
            'genre' => null,
            'release_year' => 2015,
        ]);

        Http::fake([
            'api.deezer.com/search*' => Http::response([
                'data' => [
                    $this->fakeDeezerTrack('rap-track', 'Rap Track', previewUrl: 'https://example.com/a.mp3', rank: 40_000),
                ],
            ], 200),
            'api.deezer.com/track/rap-track' => Http::response(
                $this->fakeDeezerTrackDetails('rap-track', 'Rap Track', releaseDate: '2015-06-09'),
                200,
            ),
        ]);

        // Discovery runs under a Hip-Hop filter - the previously-untagged
        // cached row shares this candidate's deezer_track_id, so caching
        // updates it in place (tagging it) rather than inserting a
        // duplicate row.
        $song = app(SongDiscoveryService::class)->findRandomSongForTier(
            new SongFilter(DifficultyTier::Extreme, SongGenre::HipHop),
        );

        $this->assertNotNull($song);
        $this->assertSame($existing->id, $song->id);
        $this->assertSame('hip_hop', $song->fresh()->genre);
        $this->assertSame('Rap Track', $song->fresh()->title);
        $this->assertDatabaseCount('songs', 1);
    }

    public function test_recognizability_score_blends_rank_and_fan_popularity_by_the_configured_weights(): void
    {
        $method = new ReflectionMethod(SongDiscoveryService::class, 'recognizabilityScore');
        $method->setAccessible(true);
        $service = app(SongDiscoveryService::class);

        // fan count at the configured ceiling -> fanPopularity 100.
        // 0.7 * 40 + 0.3 * 100 = 58.
        $this->assertSame(58, $method->invoke($service, 40, (int) config('songs.fan_score_ceiling')));
    }

    public function test_recognizability_score_falls_back_to_rank_alone_when_fan_count_is_unavailable(): void
    {
        $method = new ReflectionMethod(SongDiscoveryService::class, 'recognizabilityScore');
        $method->setAccessible(true);
        $service = app(SongDiscoveryService::class);

        // Null means "couldn't be determined", not "zero fans" - it must
        // never drag the score down by the fan weight's share.
        $this->assertSame(40, $method->invoke($service, 40, null));
    }

    public function test_fan_popularity_is_log_scaled_between_the_configured_floor_and_ceiling(): void
    {
        $method = new ReflectionMethod(SongDiscoveryService::class, 'fanPopularity');
        $method->setAccessible(true);
        $service = app(SongDiscoveryService::class);

        $this->assertSame(0, $method->invoke($service, null));
        $this->assertSame(0, $method->invoke($service, (int) config('songs.fan_score_floor')));
        $this->assertSame(100, $method->invoke($service, (int) config('songs.fan_score_ceiling')));
        // Well past the ceiling - clamped, not extrapolated past 100.
        $this->assertSame(100, $method->invoke($service, (int) config('songs.fan_score_ceiling') * 100));
    }

    public function test_discovery_persists_the_blended_recognizability_score_and_artist_stats(): void
    {
        Http::fake([
            'api.deezer.com/chart/0/tracks*' => Http::response([
                'data' => [$this->fakeDeezerTrack('famous-song', 'Famous Song', previewUrl: 'https://example.com/a.mp3', rank: 90_000)],
            ], 200),
            'api.deezer.com/track/famous-song' => Http::response([
                'id' => 'famous-song',
                'title' => 'Famous Song',
                'artist' => ['id' => 27, 'name' => 'Famous Artist'],
                'album' => ['cover_medium' => null],
                'preview' => 'https://example.com/a.mp3',
                'rank' => 90_000,
                'release_date' => '2015-06-09',
            ], 200),
            'api.deezer.com/artist/27' => Http::response(['id' => 27, 'nb_fan' => 5_000_000], 200),
        ]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy));

        $this->assertNotNull($song);
        $this->assertSame('famous-song', $song->deezer_track_id);
        $this->assertSame('27', $song->artist_deezer_id);
        $this->assertSame(5_000_000, $song->artist_fan_count);
        // The blend of rank 90 and a real, sizeable fan count still lands
        // in Easy's own band, distinct from (not necessarily equal to) raw
        // rank alone - proves the composite is what's actually cached.
        [$min, $max] = DifficultyTier::Easy->popularityRange();
        $this->assertGreaterThanOrEqual($min, $song->popularity);
        $this->assertLessThanOrEqual($max, $song->popularity);
    }

    public function test_session_context_avoids_repeating_an_artist_already_used_this_game(): void
    {
        Http::fake();

        $used = Song::factory()->forTier(DifficultyTier::Easy)->create(['artist_deezer_id' => '27']);
        $other = Song::factory()->forTier(DifficultyTier::Easy)->create(['artist_deezer_id' => '99']);

        $context = new SongSelectionContext(usedArtistDeezerIds: ['27']);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy), $context);

        $this->assertSame($other->id, $song->id);
    }

    public function test_session_context_targets_the_eras_furthest_behind_the_target_mix(): void
    {
        Http::fake();

        $currentYear = (int) now()->year;
        $classicSong = Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => $currentYear - 20]);
        Song::factory()->forTier(DifficultyTier::Easy)->create(['release_year' => $currentYear - 8]);

        // Mainstream/Current already heavily over-represented - Classic
        // (0% actual vs its 25% target) is the neediest by far.
        $context = new SongSelectionContext(eraCounts: ['mainstream' => 8, 'current' => 2, 'classic' => 0]);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy), $context);

        $this->assertSame($classicSong->id, $song->id);
    }

    public function test_an_exceptionally_popular_artist_can_repeat_once_every_other_option_is_exhausted(): void
    {
        Http::fake();

        $threshold = (int) config('songs.exceptional_artist_threshold');
        [, $max] = DifficultyTier::Easy->popularityRange();
        $exceptional = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'artist_deezer_id' => '27',
            'popularity' => max($threshold, $max), // still a valid, in-band Easy score
        ]);

        // Only one song exists at all, by an already-used artist - a repeat
        // is the only way to serve anything.
        $context = new SongSelectionContext(usedArtistDeezerIds: ['27']);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy), $context);

        $this->assertSame($exceptional->id, $song->id);
    }

    public function test_a_below_threshold_artist_repeat_is_still_served_as_an_absolute_last_resort(): void
    {
        Http::fake([
            'api.deezer.com/chart/0/tracks*' => Http::response(['data' => []], 200),
        ]);

        $threshold = (int) config('songs.exceptional_artist_threshold');
        [$min] = DifficultyTier::Easy->popularityRange();
        Song::factory()->forTier(DifficultyTier::Easy)->create([
            'artist_deezer_id' => '27',
            'popularity' => min($threshold - 1, $min), // below the exceptional bar, still in-band
        ]);

        $context = new SongSelectionContext(usedArtistDeezerIds: ['27']);

        $song = app(SongDiscoveryService::class)->findRandomSongForTier(new SongFilter(DifficultyTier::Easy), $context);

        // Never leaves the room without a song, even though variety is
        // fully given up by this point - the guaranteed-song promise from
        // the prior fix must never regress.
        $this->assertNotNull($song);
        $this->assertSame('27', $song->artist_deezer_id);
    }

    public function test_ensure_fan_count_fetches_and_caches_the_artists_fan_count(): void
    {
        $song = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'artist_deezer_id' => '27',
            'artist_fan_count' => null,
        ]);

        Http::fake([
            'api.deezer.com/artist/27' => Http::response(['id' => 27, 'name' => 'Daft Punk', 'nb_fan' => 5_194_479], 200),
        ]);

        $count = app(SongDiscoveryService::class)->ensureFanCount($song);

        $this->assertSame(5_194_479, $count);
        $this->assertSame(5_194_479, $song->fresh()->artist_fan_count);
    }

    public function test_ensure_fan_count_is_a_no_op_once_already_cached(): void
    {
        Http::fake(); // any call at all fails the test via assertNothingSent below.

        $song = Song::factory()->forTier(DifficultyTier::Easy)->create(['artist_fan_count' => 5_000_000]);

        $count = app(SongDiscoveryService::class)->ensureFanCount($song);

        $this->assertSame(5_000_000, $count);
        Http::assertNothingSent();
    }

    /**
     * A song cached before this feature existed has no artist_deezer_id at
     * all - ensureFanCount() must self-heal by recovering it via one
     * trackDetails() call before it can look up the fan count.
     */
    public function test_ensure_fan_count_recovers_a_missing_artist_id_via_track_details(): void
    {
        $song = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'artist_deezer_id' => null,
            'artist_fan_count' => null,
        ]);

        Http::fake([
            "api.deezer.com/track/{$song->deezer_track_id}" => Http::response([
                'id' => $song->deezer_track_id,
                'title' => $song->title,
                'artist' => ['id' => 27, 'name' => $song->artist],
                'album' => ['cover_medium' => null],
                'preview' => 'https://example.com/preview.mp3',
                'rank' => 500_000,
                'release_date' => '2015-06-09',
            ], 200),
            'api.deezer.com/artist/27' => Http::response(['id' => 27, 'nb_fan' => 123_456], 200),
        ]);

        $count = app(SongDiscoveryService::class)->ensureFanCount($song);

        $this->assertSame(123_456, $count);
        $this->assertSame('27', $song->fresh()->artist_deezer_id);
    }

    public function test_ensure_fan_count_returns_null_without_fabricating_a_value_when_the_artist_lookup_fails(): void
    {
        $song = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'artist_deezer_id' => '27',
            'artist_fan_count' => null,
        ]);

        Http::fake([
            'api.deezer.com/artist/27' => Http::response(['error' => ['type' => 'DataException', 'message' => 'no data', 'code' => 800]], 200),
        ]);

        $count = app(SongDiscoveryService::class)->ensureFanCount($song);

        $this->assertNull($count);
        $this->assertNull($song->fresh()->artist_fan_count);
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeDeezerTrack(
        string $id,
        string $title,
        ?string $previewUrl,
        int $rank = 500_000,
        string $artist = 'Some Artist',
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'artist' => ['name' => $artist],
            'album' => ['cover_medium' => 'https://example.com/art.jpg'],
            'preview' => $previewUrl,
            'rank' => $rank,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeDeezerTrackDetails(
        string $id,
        string $title,
        ?string $releaseDate = '2015-06-09',
        string $artist = 'Some Artist',
        int $rank = 500_000,
        ?string $previewUrl = 'https://example.com/preview.mp3',
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'artist' => ['name' => $artist],
            'album' => ['cover_medium' => 'https://example.com/art.jpg'],
            'preview' => $previewUrl,
            'rank' => $rank,
            'release_date' => $releaseDate,
        ];
    }
}
