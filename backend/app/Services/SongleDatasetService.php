<?php

namespace App\Services;

use App\Models\Dataset;
use App\Services\Music\AppleMusicClient;
use App\Services\Music\SpotifyClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imports a public Spotify playlist into a Songle dataset's track list. The
 * track list + metadata come from Spotify; each track's 30-second preview is
 * resolved through Apple's iTunes Search API (Spotify no longer serves
 * preview audio). Rows live in `dataset_tracks`, not the regenerable `songs`
 * pool. A track with no confident iTunes match is dropped from the import.
 */
class SongleDatasetService
{
    private const MAX_TRACKS = 300;

    public function __construct(
        private SpotifyClient $spotify,
        private AppleMusicClient $apple,
    ) {}

    /**
     * Replaces the dataset's tracks with the playlist's. Returns the count.
     */
    public function importPlaylist(Dataset $dataset, string $reference): int
    {
        try {
            $playlistId = $this->spotify->parsePlaylistId($reference);
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'playlist' => ['That doesn’t look like a Spotify playlist link or id.'],
            ]);
        }

        try {
            // Track list from the public playlist page - the Web API
            // playlist endpoints are 403-blocked for app tokens.
            $tracks = array_slice($this->spotify->scrapePlaylistItems($playlistId), 0, self::MAX_TRACKS);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'playlist' => ['Couldn’t read that playlist - make sure it’s public. ('.$e->getMessage().')'],
            ]);
        }

        if ($tracks === []) {
            throw ValidationException::withMessages([
                'playlist' => ['That playlist is empty or unavailable.'],
            ]);
        }

        $rows = [];
        $position = 0;
        $throttleMs = (int) config('music.itunes_throttle_ms', 3200);

        foreach ($tracks as $i => $track) {
            $preview = $this->apple->findPreview($track['artist'], $track['title']);

            if ($preview !== null) {
                $rows[] = [
                    'provider_track_id' => 'scraped:'.substr(md5(mb_strtolower($track['artist'].'|'.$track['title'])), 0, 22),
                    'title' => $track['title'],
                    'artist' => $track['artist'],
                    'album_art_url' => $preview['album_art_url'],
                    'preview_url' => $preview['preview_url'],
                    'position' => $position++,
                ];
            }

            if ($throttleMs > 0 && $i < count($tracks) - 1) {
                usleep($throttleMs * 1000);
            }
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'playlist' => ['None of that playlist’s tracks had a playable preview available.'],
            ]);
        }

        DB::transaction(function () use ($dataset, $rows) {
            $dataset->tracks()->delete();
            $dataset->tracks()->createMany($rows);
        });

        return count($rows);
    }
}
