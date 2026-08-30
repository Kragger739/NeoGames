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

    private function songleDataset(User $owner): Dataset
    {
        return Dataset::create([
            'owner_id' => $owner->id, 'name' => 'Playlist', 'type' => 'songle', 'visibility' => 'private',
        ]);
    }

    private function fakePlaylist(array $tracks): void
    {
        // First page (index=0) returns the tracks; later pages are empty so
        // the importer's paging loop stops.
        Http::fake(function ($request) use ($tracks) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response([
                'data' => ((int) ($query['index'] ?? 0)) === 0 ? $tracks : [],
            ], 200);
        });
    }

    private function track(string $id, string $title, string $artist = 'Some Artist'): array
    {
        return [
            'id' => $id, 'title' => $title, 'artist' => ['name' => $artist, 'id' => '1'],
            'album' => ['cover_medium' => 'https://example.com/art.jpg'],
            'preview' => "https://example.com/{$id}.mp3", 'rank' => 500000,
        ];
    }

    public function test_import_fills_dataset_tracks_from_a_deezer_playlist_url(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->songleDataset($owner);
        $this->fakePlaylist([
            $this->track('101', 'One'),
            $this->track('102', 'Two'),
            $this->track('103', 'Three'),
        ]);

        $response = $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/import", [
            'playlist' => 'https://www.deezer.com/en/playlist/1234567',
        ]);

        $response->assertOk();
        $response->assertJsonPath('item_count', 3);
        $this->assertDatabaseHas('dataset_tracks', ['dataset_id' => $dataset->id, 'deezer_track_id' => '102', 'position' => 1]);
    }

    public function test_import_accepts_a_bare_numeric_id_and_replaces_existing_tracks(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->songleDataset($owner);
        $dataset->tracks()->create(['deezer_track_id' => '999', 'title' => 'Old', 'artist' => 'Old', 'position' => 0]);

        $this->fakePlaylist([$this->track('201', 'New One')]);

        $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/import", ['playlist' => '7654321'])->assertOk();

        $this->assertDatabaseMissing('dataset_tracks', ['deezer_track_id' => '999']);
        $this->assertDatabaseHas('dataset_tracks', ['dataset_id' => $dataset->id, 'deezer_track_id' => '201']);
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
        Http::fake(['api.deezer.com/playlist/*/tracks*' => Http::response(['data' => []], 200)]);

        $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/import", [
            'playlist' => '1234567',
        ])->assertUnprocessable()->assertJsonValidationErrors('playlist');
    }

    public function test_a_track_can_be_removed(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->songleDataset($owner);
        $track = $dataset->tracks()->create(['deezer_track_id' => '55', 'title' => 'T', 'artist' => 'A', 'position' => 0]);

        $this->actingAs($owner)->deleteJson("/api/datasets/{$dataset->id}/tracks/{$track->id}")->assertOk();
        $this->assertDatabaseMissing('dataset_tracks', ['id' => $track->id]);
    }

    public function test_a_non_owner_cannot_import_or_remove(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $dataset = Dataset::create(['owner_id' => $owner->id, 'name' => 'P', 'type' => 'songle', 'visibility' => 'public']);
        $track = $dataset->tracks()->create(['deezer_track_id' => '5', 'title' => 'T', 'artist' => 'A', 'position' => 0]);

        $this->actingAs($other)->postJson("/api/datasets/{$dataset->id}/import", ['playlist' => '1234567'])->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/datasets/{$dataset->id}/tracks/{$track->id}")->assertForbidden();
    }

    public function test_import_rejects_a_ddf_dataset(): void
    {
        $owner = User::factory()->create();
        $ddf = Dataset::create(['owner_id' => $owner->id, 'name' => 'Q', 'type' => 'ddf', 'visibility' => 'private', 'language' => 'en']);

        $this->actingAs($owner)->postJson("/api/datasets/{$ddf->id}/import", ['playlist' => '1234567'])->assertStatus(422);
    }
}
