<?php

namespace App\Services;

use App\Models\Dataset;
use App\Services\Deezer\DeezerClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports a Deezer playlist into a Songle dataset's track list. Reuses the
 * existing DeezerClient::playlistTracks() (the same call the `iconic` genre
 * uses); the imported rows live in `dataset_tracks`, not the regenerable
 * `songs` cache.
 */
class SongleDatasetService
{
    /** Deezer's per-request cap; a few pages covers most user playlists. */
    private const PAGE_SIZE = 100;

    private const MAX_TRACKS = 300;

    public function __construct(private DeezerClient $deezer) {}

    /**
     * Replaces the dataset's tracks with the playlist's. Returns the count.
     */
    public function importPlaylist(Dataset $dataset, string $reference): int
    {
        $playlistId = $this->parsePlaylistId($reference);

        if ($playlistId === null) {
            throw ValidationException::withMessages([
                'playlist' => ['That doesn’t look like a Deezer playlist link or id.'],
            ]);
        }

        $tracks = [];

        for ($index = 0; $index < self::MAX_TRACKS; $index += self::PAGE_SIZE) {
            $page = $this->deezer->playlistTracks($playlistId, self::PAGE_SIZE, $index);

            if ($page === []) {
                break;
            }

            foreach ($page as $track) {
                $tracks[$track['deezer_track_id']] = $track;
            }
        }

        if ($tracks === []) {
            throw ValidationException::withMessages([
                'playlist' => ['That playlist is empty or unavailable.'],
            ]);
        }

        $rows = [];
        $position = 0;
        foreach ($tracks as $track) {
            $rows[] = [
                'deezer_track_id' => $track['deezer_track_id'],
                'title' => $track['title'],
                'artist' => $track['artist'],
                'album_art_url' => $track['album_art_url'],
                'preview_url' => $track['preview_url'],
                'position' => $position++,
            ];
        }

        DB::transaction(function () use ($dataset, $rows) {
            $dataset->tracks()->delete();
            $dataset->tracks()->createMany($rows);
        });

        return count($rows);
    }

    /**
     * Accepts a bare numeric id or any deezer.com URL containing
     * /playlist/{id} (e.g. https://www.deezer.com/en/playlist/1234567).
     */
    public function parsePlaylistId(string $reference): ?string
    {
        $reference = trim($reference);

        if (preg_match('/^\d{3,}$/', $reference)) {
            return $reference;
        }

        if (preg_match('#playlist/(\d{3,})#', $reference, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
