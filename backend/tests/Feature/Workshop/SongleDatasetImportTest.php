<?php

namespace Tests\Feature\Workshop;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SongleDatasetImportTest extends TestCase
{
    use RefreshDatabase;

    private const PLAYLIST_ID = 'abcdefghijABCDEFGHIJ12';

    protected function setUp(): void
    {
        parent::setUp();
        config(['music.itunes_throttle_ms' => 0]);
    }

    private function songleDataset(User $owner): Dataset
    {
        return Dataset::create([
            'owner_id' => $owner->id, 'name' => 'Playlist', 'type' => 'songle', 'visibility' => 'private',
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2?: string}>  $tracks  [id, title, artist?]
     */
    private function fakePlaylist(array $tracks): void
    {
        $this->fakeSpotifyToken();

        $items = array_map(fn (array $t) => ['track' => [
            'id' => $t[0], 'name' => $t[1], 'popularity' => 50, 'is_local' => false,
            'external_ids' => ['isrc' => 'X'],
            'artists' => [['id' => 'art-1', 'name' => $t[2] ?? 'Some Artist']],
            'album' => ['release_date' => '2015-06-09', 'images' => [['url' => 'https://example.com/art.jpg']]],
        ]], $tracks);

        Http::fake([
            'api.spotify.com/v1/playlists/*/items*' => Http::response(['items' => $items, 'next' => null], 200),
            'api.spotify.com/v1/artists*' => Http::response(['artists' => [['id' => 'art-1', 'followers' => ['total' => 1000]]]], 200),
            'itunes.apple.com/search*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                // term is "<artist> <title>"; the fixture artist is always "Some Artist".
                $title = trim(str_replace('Some Artist', '', $q['term'] ?? ''));

                return Http::response(['results' => [[
                    'kind' => 'song', 'trackId' => 1, 'trackName' => $title, 'artistName' => 'Some Artist',
                    'previewUrl' => 'https://audio-ssl.itunes.apple.com/x.m4a',
                    'artworkUrl100' => 'https://is1.mzstatic.com/100x100bb.jpg', 'releaseDate' => '2015-06-09T00:00:00Z',
                ]]], 200);
            },
        ]);
    }

    public function test_import_fills_dataset_tracks_from_a_spotify_playlist_url(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->songleDataset($owner);
        $this->fakePlaylist([['101', 'One'], ['102', 'Two'], ['103', 'Three']]);

        $response = $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/import", [
            'playlist' => 'https://open.spotify.com/playlist/'.self::PLAYLIST_ID,
        ]);

        $response->assertOk();
        $response->assertJsonPath('item_count', 3);
        $this->assertDatabaseHas('dataset_tracks', ['dataset_id' => $dataset->id, 'provider_track_id' => '102', 'position' => 1]);
    }

    public function test_import_accepts_a_bare_id_and_replaces_existing_tracks(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->songleDataset($owner);
        $dataset->tracks()->create(['provider_track_id' => '999', 'title' => 'Old', 'artist' => 'Old', 'position' => 0]);

        $this->fakePlaylist([['201', 'New One']]);

        $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/import", ['playlist' => self::PLAYLIST_ID])->assertOk();

        $this->assertDatabaseMissing('dataset_tracks', ['provider_track_id' => '999']);
        $this->assertDatabaseHas('dataset_tracks', ['dataset_id' => $dataset->id, 'provider_track_id' => '201']);
        $this->assertSame(1, $dataset->tracks()->count());
    }

    public function test_a_bad_reference_is_rejected(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->songleDataset($owner);

        $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/import", [
            'playlist' => 'not a playlist',
        ])->assertUnprocessable()->assertJsonValidationErrors('playlist');
    }

    public function test_an_empty_playlist_is_rejected(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->songleDataset($owner);
        $this->fakeSpotifyToken();
        Http::fake(['api.spotify.com/v1/playlists/*/items*' => Http::response(['items' => [], 'next' => null], 200)]);

        $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/import", [
            'playlist' => self::PLAYLIST_ID,
        ])->assertUnprocessable()->assertJsonValidationErrors('playlist');
    }

    public function test_a_track_can_be_removed(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->songleDataset($owner);
        $track = $dataset->tracks()->create(['provider_track_id' => '55', 'title' => 'T', 'artist' => 'A', 'position' => 0]);

        $this->actingAs($owner)->deleteJson("/api/datasets/{$dataset->id}/tracks/{$track->id}")->assertOk();
        $this->assertDatabaseMissing('dataset_tracks', ['id' => $track->id]);
    }

    public function test_a_non_owner_cannot_import_or_remove(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $dataset = Dataset::create(['owner_id' => $owner->id, 'name' => 'P', 'type' => 'songle', 'visibility' => 'public']);
        $track = $dataset->tracks()->create(['provider_track_id' => '5', 'title' => 'T', 'artist' => 'A', 'position' => 0]);

        $this->actingAs($other)->postJson("/api/datasets/{$dataset->id}/import", ['playlist' => self::PLAYLIST_ID])->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/datasets/{$dataset->id}/tracks/{$track->id}")->assertForbidden();
    }

    public function test_import_rejects_a_ddf_dataset(): void
    {
        $owner = User::factory()->create();
        $ddf = Dataset::create(['owner_id' => $owner->id, 'name' => 'Q', 'type' => 'ddf', 'visibility' => 'private', 'language' => 'en']);

        $this->actingAs($owner)->postJson("/api/datasets/{$ddf->id}/import", ['playlist' => self::PLAYLIST_ID])->assertStatus(422);
    }
}
