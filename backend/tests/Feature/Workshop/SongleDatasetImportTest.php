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
     * @param  array<int, string>  $titles  song titles (artist is always "Some Artist")
     */
    private function fakePlaylist(array $titles): void
    {
        $this->fakeSpotifyToken();
        $this->fakeSpotifyPlaylistPage(array_map(fn ($t) => [$t, 'Some Artist'], $titles));

        Http::fake([
            'itunes.apple.com/search*' => function ($request) {
                $title = trim(str_replace('Some Artist', '', urldecode($request->url())));
                $title = trim(preg_replace('#.*/search\?term=#', '', $title));
                $title = trim(explode('&', $title)[0]);

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
        $this->fakePlaylist(['One', 'Two', 'Three']);

        $response = $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/import", [
            'playlist' => 'https://open.spotify.com/playlist/'.self::PLAYLIST_ID,
        ]);

        $response->assertOk();
        $response->assertJsonPath('item_count', 3);
        $this->assertDatabaseHas('dataset_tracks', ['dataset_id' => $dataset->id, 'title' => 'Two', 'position' => 1]);
    }

    public function test_import_accepts_a_bare_id_and_replaces_existing_tracks(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->songleDataset($owner);
        $dataset->tracks()->create(['provider_track_id' => '999', 'title' => 'Old', 'artist' => 'Old', 'position' => 0]);

        $this->fakePlaylist(['New One']);

        $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/import", ['playlist' => self::PLAYLIST_ID])->assertOk();

        $this->assertDatabaseMissing('dataset_tracks', ['provider_track_id' => '999']);
        $this->assertDatabaseHas('dataset_tracks', ['dataset_id' => $dataset->id, 'title' => 'New One']);
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
        Http::fake(['open.spotify.com/embed/playlist/*' => Http::response(
            '<html><script id="__NEXT_DATA__" type="application/json">{"props":{}}</script></html>', 200,
        )]);

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
