<?php

namespace App\Services\Music;

use App\Models\Song;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns a Spotify track (metadata + popularity) into a playable row in the
 * `songs` pool by resolving its 30-second preview + artwork through Apple's
 * iTunes Search API. Shared by the `songs:sync` command (bulk playlist
 * seeding) and SongDiscoveryService's Artist-genre pool priming.
 *
 * The preview clip itself is downloaded and cached on the 'public' disk
 * (song-previews/) so playback never depends on Apple's CDN staying up or
 * reachable; the stored preview_url points at NeoGames' own copy. A failed
 * download falls back to the direct Apple URL rather than dropping the song.
 *
 * The iTunes Search API rate-limits around 20 req/min, so every path that
 * loops over tracks sleeps `throttleMs` between preview lookups. `songs:sync`
 * passes the full config value; the interactive Artist path passes a small
 * one (a handful of calls in a burst is fine).
 */
class SongPoolSeeder
{
    private const PREVIEW_DIR = 'song-previews';

    public function __construct(
        private SpotifyClient $spotify,
        private AppleMusicClient $apple,
    ) {}

    /**
     * Resolve + upsert one normalized Spotify track (see
     * SpotifyClient::normalizeTrack). Returns the Song, or null when iTunes
     * has no confident preview for it.
     *
     * @param  array<string, mixed>  $spotifyTrack
     */
    public function persist(array $spotifyTrack, ?string $genreTag, ?int $followerCount): ?Song
    {
        $preview = $this->apple->findPreview($spotifyTrack['artist'], $spotifyTrack['title']);

        if ($preview === null) {
            return null;
        }

        $attributes = [
            'title' => $spotifyTrack['title'],
            'artist' => $spotifyTrack['artist'],
            'artist_provider_id' => $spotifyTrack['artist_provider_id'] ?? null,
            'artist_follower_count' => $followerCount,
            'preview_url' => $this->cachePreview(
                $spotifyTrack['provider_track_id'],
                $preview['preview_url'],
            ),
            'album_art_url' => $spotifyTrack['album_art_url'] ?? $preview['album_art_url'],
            'popularity' => (int) ($spotifyTrack['popularity'] ?? 0),
            'release_year' => $spotifyTrack['release_year'] ?? $preview['release_year'],
        ];

        // Monotonic genre tag - only ever set it, never clear it back to null
        // on a later untagged pass (a track can be on both a Pop and an
        // Iconic playlist).
        if ($genreTag !== null) {
            $attributes['genre'] = $genreTag;
        }

        return Song::updateOrCreate(
            ['provider_track_id' => $spotifyTrack['provider_track_id']],
            $attributes,
        );
    }

    /**
     * Download the Apple preview clip to the 'public' disk once and return a
     * host-relative URL to our own copy. Re-sync of an already-cached track
     * skips the download. Any failure falls back to the direct Apple URL so
     * the song is still playable.
     */
    private function cachePreview(string $providerTrackId, string $appleUrl): string
    {
        $path = self::PREVIEW_DIR.'/'.$providerTrackId.'.m4a';
        $disk = Storage::disk('public');

        try {
            if (! $disk->exists($path)) {
                $response = Http::timeout(8)->get($appleUrl);

                if (! $response->successful() || $response->body() === '') {
                    return $appleUrl;
                }

                $disk->put($path, $response->body());
            }

            return parse_url($disk->url($path), PHP_URL_PATH) ?: $appleUrl;
        } catch (Throwable) {
            return $appleUrl;
        }
    }

    /**
     * Seed one genre's pool from a Spotify playlist reference (id or URL).
     *
     * @param  (callable(string $line): void)|null  $log
     * @return array{seeded: int, skipped: int}
     */
    public function seedPlaylist(string $playlistRef, ?string $genreTag, ?int $throttleMs = null, ?callable $log = null): array
    {
        $throttleMs ??= (int) config('music.itunes_throttle_ms', 3200);
        // Track list from the public playlist page (the Web API playlist
        // endpoints are 403-blocked for app tokens); popularity + ids per
        // track from a best-effort search.
        $rows = $this->spotify->scrapePlaylistItems($playlistRef);

        $seeded = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            $track = $this->spotify->resolveTrack($row['title'], $row['artist']);
            $song = $this->persist($track, $genreTag, null);

            if ($song) {
                $seeded++;
            } else {
                $skipped++;
                $log && $log("  no preview: {$row['artist']} - {$row['title']}");
            }

            if ($throttleMs > 0 && $i < count($rows) - 1) {
                usleep($throttleMs * 1000);
            }
        }

        return ['seeded' => $seeded, 'skipped' => $skipped];
    }

    /**
     * Seed an artist's Spotify top tracks into the pool. `$genreTag` is null
     * for the Artist / MultiArtist genres (matched on the songs.artist
     * column), but set for German Rap, which seeds from a fixed artist list.
     */
    public function seedArtistTopTracks(string $artistId, int $throttleMs = 250, ?string $genreTag = null): int
    {
        $tracks = $this->spotify->artistTopTracks($artistId);
        $follower = $this->spotify->artistFollowerCount($artistId);
        $seeded = 0;

        foreach ($tracks as $i => $track) {
            if ($this->persist($track, $genreTag, $follower)) {
                $seeded++;
            }

            if ($throttleMs > 0 && $i < count($tracks) - 1) {
                usleep($throttleMs * 1000);
            }
        }

        return $seeded;
    }
}
