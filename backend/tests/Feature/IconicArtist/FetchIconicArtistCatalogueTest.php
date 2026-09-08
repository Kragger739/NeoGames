<?php

namespace Tests\Feature\IconicArtist;

use App\Jobs\FetchIconicArtistCatalogue;
use App\Models\IconicArtist;
use App\Models\IconicArtistSong;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchIconicArtistCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array{id: string, name: string, pop: int}>  $tracks
     * @param  array<int, string>  $noPreview  track names iTunes has no match for
     */
    private function fakeMusic(array $tracks, array $noPreview = []): void
    {
        $this->fakeSpotifyToken();

        $byId = collect($tracks)->keyBy('id');

        Http::fake(function (Request $request) use ($tracks, $byId, $noPreview) {
            $url = $request->url();

            if (str_contains($url, 'accounts.spotify.com/api/token')) {
                return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
            }

            if (str_contains($url, '/v1/search')) {
                return Http::response(['artists' => ['items' => [
                    ['id' => 'ART1', 'name' => 'Queen', 'images' => [], 'followers' => ['total' => 9999]],
                ]]]);
            }

            if (str_contains($url, '/v1/artists/ART1/albums')) {
                return Http::response(['items' => [
                    ['id' => 'AL1', 'name' => 'Album One'],
                    ['id' => 'AL2', 'name' => 'Album Two'],
                ], 'next' => null]);
            }

            if (str_contains($url, '/v1/albums/AL1/tracks')) {
                return Http::response(['items' => collect($tracks)->take(2)->map(fn ($t) => [
                    'id' => $t['id'], 'name' => $t['name'], 'artists' => [['id' => 'ART1']],
                ])->all(), 'next' => null]);
            }

            if (str_contains($url, '/v1/albums/AL2/tracks')) {
                return Http::response(['items' => [
                    ...collect($tracks)->slice(2)->map(fn ($t) => [
                        'id' => $t['id'], 'name' => $t['name'], 'artists' => [['id' => 'ART1']],
                    ])->all(),
                    // A guest feature crediting a different artist - must be dropped.
                    ['id' => 'GUEST', 'name' => 'Not Ours', 'artists' => [['id' => 'OTHER']]],
                ], 'next' => null]);
            }

            if (str_contains($url, '/v1/tracks')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $ids = explode(',', $q['ids'] ?? '');

                return Http::response(['tracks' => collect($ids)
                    ->map(fn ($id) => $byId->get($id))
                    ->filter()
                    ->map(fn ($t) => [
                        'id' => $t['id'],
                        'name' => $t['name'],
                        'popularity' => $t['pop'],
                        'artists' => [['id' => 'ART1', 'name' => 'Queen']],
                        'album' => ['images' => [['url' => 'https://img/art.jpg']], 'release_date' => '1975'],
                        'external_ids' => ['isrc' => 'X'],
                    ])->values()->all()]);
            }

            if (str_contains($url, 'itunes.apple.com/search')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $term = $q['term'] ?? '';
                $title = trim(str_replace('Queen', '', $term));

                if (in_array($title, $noPreview, true)) {
                    return Http::response(['results' => []]);
                }

                return Http::response(['results' => [[
                    'kind' => 'song',
                    'artistName' => 'Queen',
                    'trackName' => $title,
                    'previewUrl' => 'https://example.com/p.m4a',
                    'artworkUrl100' => 'https://example.com/a100.jpg',
                    'trackId' => crc32($title),
                    'releaseDate' => '1975-01-01',
                ]]]);
            }

            return Http::response([], 404);
        });

        config(['music.itunes_throttle_ms' => 0]);
    }

    private function runFetch(IconicArtist $artist): void
    {
        $artist->forceFill(['fetch_run_token' => 'tok', 'fetch_status' => 'pending'])->save();
        FetchIconicArtistCatalogue::dispatch($artist->id, 'tok'); // sync queue -> runs the whole chain inline
    }

    public function test_discovery_builds_popularity_ranked_rows_and_seeds_top_first(): void
    {
        $tracks = [
            ['id' => 'T1', 'name' => 'Big Hit', 'pop' => 95],
            ['id' => 'T2', 'name' => 'Mid Hit', 'pop' => 70],
            ['id' => 'T3', 'name' => 'Deep Cut', 'pop' => 40],
        ];
        $this->fakeMusic($tracks);

        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $this->runFetch($artist);

        $artist->refresh();
        $this->assertSame('done', $artist->fetch_status);
        $this->assertSame(3, $artist->fetched_total);
        $this->assertSame(3, $artist->fetched_playable);

        $rows = IconicArtistSong::where('iconic_artist_id', $artist->id)->orderBy('rank')->get();
        $this->assertSame(['Big Hit', 'Mid Hit', 'Deep Cut'], $rows->pluck('title')->all());
        $this->assertSame([1, 2, 3], $rows->pluck('rank')->all());
        $rows->each(fn ($r) => $this->assertNotNull($r->song_id));

        // The guest-feature track was dropped.
        $this->assertDatabaseMissing('iconic_artist_songs', ['provider_track_id' => 'GUEST']);
    }

    public function test_a_track_with_no_itunes_preview_is_marked_unplayable(): void
    {
        $tracks = [
            ['id' => 'T1', 'name' => 'Playable', 'pop' => 90],
            ['id' => 'T2', 'name' => 'No Preview', 'pop' => 80],
        ];
        $this->fakeMusic($tracks, noPreview: ['No Preview']);

        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $this->runFetch($artist);

        $this->assertDatabaseHas('iconic_artist_songs', [
            'iconic_artist_id' => $artist->id, 'title' => 'No Preview',
            'song_id' => null, 'unplayable' => true,
        ]);
        $this->assertSame(1, $artist->fresh()->fetched_playable);
    }

    public function test_an_unresolvable_artist_name_fails_with_a_message(): void
    {
        $this->fakeSpotifyToken();
        Http::fake([
            'accounts.spotify.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.spotify.com/v1/search*' => Http::response(['artists' => ['items' => []]]),
        ]);

        $artist = IconicArtist::factory()->create(['name' => 'Zzxqphhh']);
        $this->runFetch($artist);

        $artist->refresh();
        $this->assertSame('failed', $artist->fetch_status);
        $this->assertStringContainsString('Zzxqphhh', $artist->fetch_error);
        $this->assertSame(0, IconicArtistSong::where('iconic_artist_id', $artist->id)->count());
    }

    public function test_a_re_fetch_supersedes_an_in_flight_chain(): void
    {
        $tracks = [['id' => 'T1', 'name' => 'Song A', 'pop' => 90]];
        $this->fakeMusic($tracks);

        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $artist->forceFill(['fetch_run_token' => 'OLD', 'fetch_status' => 'seeding'])->save();

        // Old token no longer matches -> the job is a no-op.
        FetchIconicArtistCatalogue::dispatch($artist->id, 'OLD');
        $artist->refresh();
        $this->assertSame('OLD', $artist->fetch_run_token); // untouched by the stale run
        // (nothing seeded because discovery never ran under the old token here)

        $artist->forceFill(['fetch_run_token' => 'NEW', 'fetch_status' => 'pending'])->save();
        FetchIconicArtistCatalogue::dispatch($artist->id, 'NEW');

        $this->assertSame('done', $artist->fresh()->fetch_status);
        $this->assertSame(1, IconicArtistSong::where('iconic_artist_id', $artist->id)->count());
    }
}
