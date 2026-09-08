<?php

namespace Tests\Feature\IconicArtist;

use App\Jobs\FetchIconicArtistCatalogue;
use App\Models\IconicArtist;
use App\Models\IconicArtistSong;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchIconicArtistCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, array<string, mixed>>  $results */
    private function fakeItunes(array $results, int $status = 200): void
    {
        Http::fake([
            'itunes.apple.com/search*' => Http::response(
                ['resultCount' => count($results), 'results' => $results],
                $status,
            ),
        ]);
    }

    private function song(string $id, string $title, string $artist = 'Queen', ?string $preview = 'https://cdn.apple/p.m4a', ?int $artistId = null): array
    {
        return [
            'wrapperType' => 'track',
            'kind' => 'song',
            'trackId' => $id,
            'trackName' => $title,
            'artistName' => $artist,
            'artistId' => $artistId,
            'previewUrl' => $preview,
            'artworkUrl100' => 'https://cdn.apple/a100bb.jpg',
            'releaseDate' => '1975-11-21T08:00:00Z',
        ];
    }

    private function runFetch(IconicArtist $artist): void
    {
        $artist->forceFill(['fetch_run_token' => 'tok', 'fetch_status' => 'pending'])->save();
        FetchIconicArtistCatalogue::dispatch($artist->id, 'tok'); // sync queue -> runs inline
    }

    public function test_it_builds_ranked_rows_from_the_itunes_result_order(): void
    {
        $this->fakeItunes([
            $this->song('1', 'Bohemian Rhapsody'),
            $this->song('2', 'Somebody to Love'),
            $this->song('3', 'Under Pressure'),
        ]);

        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $this->runFetch($artist);

        $artist->refresh();
        $this->assertSame('done', $artist->fetch_status);
        $this->assertSame(3, $artist->fetched_total);
        $this->assertSame(3, $artist->fetched_playable);

        $rows = IconicArtistSong::where('iconic_artist_id', $artist->id)->orderBy('rank')->get();
        $this->assertSame(['Bohemian Rhapsody', 'Somebody to Love', 'Under Pressure'], $rows->pluck('title')->all());
        $this->assertSame([1, 2, 3], $rows->pluck('rank')->all());
        $rows->each(function (IconicArtistSong $r) {
            $this->assertNotNull($r->song_id);
            $this->assertStringStartsWith('itunes:', $r->provider_track_id);
        });

        // A real Song row exists per track (synthetic provider id).
        $this->assertDatabaseHas('songs', ['provider_track_id' => 'itunes:1', 'title' => 'Bohemian Rhapsody']);
    }

    public function test_it_drops_other_artists_and_de_dupes_by_title(): void
    {
        $this->fakeItunes([
            $this->song('1', 'Under Pressure'),
            $this->song('2', 'Under Pressure - Remastered 2011'),         // dupe by normalized title
            $this->song('3', 'Cool Cat', 'Queen & David Bowie'),          // different artist -> dropped
            $this->song('4', 'Radio Ga Ga'),
        ]);

        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $this->runFetch($artist);

        $titles = IconicArtistSong::where('iconic_artist_id', $artist->id)->orderBy('rank')->pluck('title')->all();
        $this->assertSame(['Under Pressure', 'Radio Ga Ga'], $titles);
    }

    public function test_it_pins_the_catalogue_to_the_apple_artist_id(): void
    {
        $this->fakeItunes([
            $this->song('1', 'Real Hit', 'The Band', artistId: 111),
            $this->song('2', 'Impostor Cover', 'The Band', artistId: 222), // same name, other id
            $this->song('3', 'Another Real One', 'The Band', artistId: 111),
        ]);

        $artist = IconicArtist::factory()->create(['name' => 'The Band', 'apple_artist_id' => 111]);
        $this->runFetch($artist);

        $titles = IconicArtistSong::where('iconic_artist_id', $artist->id)->orderBy('rank')->pluck('title')->all();
        $this->assertSame(['Real Hit', 'Another Real One'], $titles);
    }

    public function test_a_pinned_artist_merges_the_search_head_and_the_id_lookup_tail(): void
    {
        Http::fake([
            'itunes.apple.com/search*' => Http::response(['resultCount' => 2, 'results' => [
                $this->song('1', 'Big Single', 'The Band', artistId: 111),
                $this->song('2', 'Shared Track', 'The Band', artistId: 111),
            ]], 200),
            'itunes.apple.com/lookup*' => Http::response(['resultCount' => 3, 'results' => [
                ['wrapperType' => 'artist', 'artistId' => 111, 'artistName' => 'The Band'],
                $this->song('2', 'Shared Track', 'The Band', artistId: 111), // dupe by track id
                $this->song('3', 'Deep Cut', 'The Band', artistId: 111),
            ]], 200),
        ]);

        $artist = IconicArtist::factory()->create(['name' => 'The Band', 'apple_artist_id' => 111]);
        $this->runFetch($artist);

        $titles = IconicArtistSong::where('iconic_artist_id', $artist->id)->orderBy('rank')->pluck('title')->all();
        // Search head first, then the lookup tail, no duplicate for track id 2.
        $this->assertSame(['Big Single', 'Shared Track', 'Deep Cut'], $titles);
    }

    public function test_a_misspelled_pinned_name_still_gets_the_catalogue_from_the_lookup(): void
    {
        Http::fake([
            'itunes.apple.com/search*' => Http::response(['resultCount' => 0, 'results' => []], 200),
            'itunes.apple.com/lookup*' => Http::response(['resultCount' => 2, 'results' => [
                ['wrapperType' => 'artist', 'artistId' => 111, 'artistName' => 'The Band'],
                $this->song('9', 'Found By Lookup', 'The Band', artistId: 111),
            ]], 200),
        ]);

        $artist = IconicArtist::factory()->create(['name' => 'Th3 B4nd Mispelled', 'apple_artist_id' => 111]);
        $this->runFetch($artist);

        $this->assertSame('done', $artist->fresh()->fetch_status);
        $this->assertDatabaseHas('iconic_artist_songs', [
            'iconic_artist_id' => $artist->id, 'title' => 'Found By Lookup',
        ]);
    }

    public function test_songs_without_a_preview_are_skipped(): void
    {
        $this->fakeItunes([
            $this->song('1', 'Playable'),
            $this->song('2', 'No Preview', preview: null),
        ]);

        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $this->runFetch($artist);

        $this->assertSame(1, IconicArtistSong::where('iconic_artist_id', $artist->id)->count());
        $this->assertSame(1, $artist->fresh()->fetched_playable);
    }

    public function test_an_empty_itunes_result_fails_with_a_message(): void
    {
        $this->fakeItunes([]);

        $artist = IconicArtist::factory()->create(['name' => 'Zzxqphhh']);
        $this->runFetch($artist);

        $artist->refresh();
        $this->assertSame('failed', $artist->fetch_status);
        $this->assertStringContainsString('Zzxqphhh', $artist->fetch_error);
    }

    public function test_a_re_fetch_supersedes_an_in_flight_chain(): void
    {
        $this->fakeItunes([$this->song('1', 'Song A')]);

        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $artist->forceFill(['fetch_run_token' => 'OLD', 'fetch_status' => 'pending'])->save();

        // Stale token -> no-op.
        FetchIconicArtistCatalogue::dispatch($artist->id, 'STALE');
        $this->assertSame(0, IconicArtistSong::where('iconic_artist_id', $artist->id)->count());

        $artist->forceFill(['fetch_run_token' => 'NEW', 'fetch_status' => 'pending'])->save();
        FetchIconicArtistCatalogue::dispatch($artist->id, 'NEW');

        $this->assertSame('done', $artist->fresh()->fetch_status);
        $this->assertSame(1, IconicArtistSong::where('iconic_artist_id', $artist->id)->count());
    }

    public function test_a_re_fetch_demotes_rows_that_dropped_out(): void
    {
        $artist = IconicArtist::factory()->create(['name' => 'Queen']);
        $stale = Song::factory()->create();
        IconicArtistSong::factory()->create([
            'iconic_artist_id' => $artist->id,
            'provider_track_id' => 'itunes:OLD',
            'song_id' => $stale->id,
            'rank' => 1,
        ]);

        $this->fakeItunes([$this->song('99', 'Fresh Hit')]);
        $this->runFetch($artist);

        $this->assertDatabaseHas('iconic_artist_songs', [
            'iconic_artist_id' => $artist->id, 'provider_track_id' => 'itunes:OLD',
            'rank' => null, 'unplayable' => true,
        ]);
        $this->assertDatabaseHas('iconic_artist_songs', [
            'iconic_artist_id' => $artist->id, 'provider_track_id' => 'itunes:99', 'rank' => 1,
        ]);
    }
}
