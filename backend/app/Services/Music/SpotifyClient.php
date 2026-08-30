<?php

namespace App\Services\Music;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Spotify Web API, app-only (client-credentials) - supplies song search,
 * metadata, and the `popularity` signal (0-100) that drives Songle's
 * difficulty tiers. It deliberately does NOT supply audio: Spotify's
 * `preview_url` has been null under client-credentials since their Nov 2024
 * API changes, so the 30-second clip is fetched from Apple's iTunes Search
 * API instead (see AppleMusicClient).
 *
 * This client is only exercised by the `songs:sync` command and the
 * Artist-genre pool priming job - never inside a game round's request path,
 * which reads the pre-seeded `songs` table directly.
 */
class SpotifyClient
{
    private const ACCOUNTS_URL = 'https://accounts.spotify.com';

    private const API_URL = 'https://api.spotify.com/v1';

    /** Access tokens last 3600s; refresh a little early. */
    private const TOKEN_TTL = 3300;

    /**
     * @return array<int, NormalizedSpotifyTrack>
     */
    public function searchTrack(string $query, int $limit = 5): array
    {
        $body = $this->get('/search', [
            'q' => $query,
            'type' => 'track',
            'limit' => $limit,
            'market' => config('music.spotify_market', 'US'),
        ]);

        return array_values(array_filter(array_map(
            fn (array $track) => $this->normalizeTrack($track),
            $body['tracks']['items'] ?? [],
        )));
    }

    /**
     * Every track on a playlist, paged. Skips removed (`track` null) and
     * local-file entries. `$playlistRef` may be a bare id or an
     * open.spotify.com URL.
     *
     * @return array<int, NormalizedSpotifyTrack>
     */
    public function playlistTracks(string $playlistRef, int $limit = 500): array
    {
        $playlistId = $this->parsePlaylistId($playlistRef);
        $out = [];
        $offset = 0;
        $market = config('music.spotify_market', 'US');

        // Spotify has been inconsistent about playlist reads for app tokens:
        // /tracks is deprecated and often edge-blocked, and the `market`
        // param sometimes triggers a 403 by itself. Try the variants in
        // order and stick with the first that works.
        $variants = [
            ['path' => 'items', 'market' => $market],
            ['path' => 'items', 'market' => null],
            ['path' => 'tracks', 'market' => null],
        ];

        do {
            $body = null;

            foreach ($variants as $i => $v) {
                try {
                    $body = $this->get("/playlists/{$playlistId}/{$v['path']}", array_filter([
                        'limit' => 100,
                        'offset' => $offset,
                        'market' => $v['market'],
                    ], fn ($x) => $x !== null));
                    // Pin the working variant for the remaining pages.
                    $variants = [$v];
                    break;
                } catch (RuntimeException $e) {
                    if ($i === count($variants) - 1) {
                        throw $e;
                    }
                }
            }

            foreach ($body['items'] ?? [] as $item) {
                $track = $item['track'] ?? null;

                if (! $track || ($track['is_local'] ?? false)) {
                    continue;
                }

                if ($normalized = $this->normalizeTrack($track)) {
                    $out[] = $normalized;
                }
            }

            $offset += 100;
        } while (! empty($body['next']) && $offset < $limit);

        return $out;
    }

    /**
     * The artist's own top tracks (Spotify's popularity-ordered set) - used
     * for the Artist / MultiArtist genres.
     *
     * @return array<int, NormalizedSpotifyTrack>
     */
    public function artistTopTracks(string $artistId): array
    {
        $body = $this->get("/artists/{$artistId}/top-tracks", [
            'market' => config('music.spotify_market', 'US'),
        ]);

        return array_values(array_filter(array_map(
            fn (array $track) => $this->normalizeTrack($track),
            $body['tracks'] ?? [],
        )));
    }

    /**
     * Live artist-search for the room-settings autocomplete.
     *
     * @return array<int, array{provider_artist_id: string, name: string, picture_url: ?string, follower_count: int}>
     */
    public function searchArtists(string $query, int $limit = 8): array
    {
        $body = $this->get('/search', [
            'q' => $query,
            'type' => 'artist',
            'limit' => 25,
        ]);

        $results = array_map(fn (array $artist) => [
            'provider_artist_id' => (string) $artist['id'],
            'name' => $artist['name'],
            'picture_url' => $artist['images'][0]['url'] ?? null,
            'follower_count' => (int) ($artist['followers']['total'] ?? 0),
        ], $body['artists']['items'] ?? []);

        usort($results, fn (array $a, array $b) => $b['follower_count'] <=> $a['follower_count']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Resolve a display name to a Spotify artist id. Same strategy as the old
     * Deezer path: exact case-insensitive name match wins, highest follower
     * count among those; otherwise highest follower count overall; null only
     * if Spotify returns nothing.
     */
    public function findArtistId(string $name): ?string
    {
        $results = $this->searchArtists($name, limit: 25);

        if ($results === []) {
            return null;
        }

        $normalized = mb_strtolower(trim($name));
        $exact = array_filter(
            $results,
            fn (array $artist) => mb_strtolower(trim($artist['name'])) === $normalized,
        );

        $candidates = $exact !== [] ? $exact : $results;
        usort($candidates, fn (array $a, array $b) => $b['follower_count'] <=> $a['follower_count']);

        return $candidates[array_key_first($candidates)]['provider_artist_id'];
    }

    /**
     * Batch artist lookup - `songs:sync` uses this to attach a follower count
     * to every seeded track without one call per track.
     *
     * @param  array<int, string>  $ids
     * @return array<string, int>  provider_artist_id => follower_count
     */
    public function artistFollowerCounts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $out = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            $body = $this->get('/artists', ['ids' => implode(',', $chunk)]);

            foreach ($body['artists'] ?? [] as $artist) {
                if ($artist === null) {
                    continue;
                }

                $out[(string) $artist['id']] = (int) ($artist['followers']['total'] ?? 0);
            }
        }

        return $out;
    }

    public function artistFollowerCount(string $artistId): ?int
    {
        $body = $this->get("/artists/{$artistId}", []);

        return isset($body['followers']['total']) ? (int) $body['followers']['total'] : null;
    }

    /**
     * Accepts a bare base-62 id or any open.spotify.com/playlist/{id} URL
     * (optional locale segment, optional ?si= tracking suffix).
     */
    public function parsePlaylistId(string $reference): string
    {
        $reference = trim($reference);

        if (preg_match('/^[A-Za-z0-9]{22}$/', $reference)) {
            return $reference;
        }

        if (preg_match('#playlist[/:]([A-Za-z0-9]{22})#', $reference, $m)) {
            return $m[1];
        }

        throw new RuntimeException("Not a Spotify playlist reference: {$reference}");
    }

    /**
     * @return NormalizedSpotifyTrack|null  null if the track is missing an id
     */
    private function normalizeTrack(array $track): ?array
    {
        if (! isset($track['id'])) {
            return null;
        }

        return [
            'provider_track_id' => (string) $track['id'],
            'isrc' => $track['external_ids']['isrc'] ?? null,
            'title' => $track['name'],
            'artist' => $track['artists'][0]['name'] ?? '',
            'artist_provider_id' => isset($track['artists'][0]['id']) ? (string) $track['artists'][0]['id'] : null,
            'album_art_url' => $track['album']['images'][0]['url'] ?? null,
            'popularity' => (int) ($track['popularity'] ?? 0),
            'release_year' => $this->parseReleaseYear($track['album']['release_date'] ?? null),
        ];
    }

    private function parseReleaseYear(?string $releaseDate): ?int
    {
        if (! $releaseDate) {
            return null;
        }

        // Spotify gives "2001", "2001-06", or "2001-06-15" per release_date_precision.
        if (preg_match('/^(\d{4})/', $releaseDate, $m)) {
            return (int) $m[1];
        }

        try {
            return Carbon::parse($releaseDate)->year;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get(self::API_URL.$path, $query);

        if ($response->status() === 401) {
            // Token rejected (rotated secret, clock skew) - drop the cached
            // one and try exactly once more with a fresh token.
            Cache::forget('music:spotify-token');
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->get(self::API_URL.$path, $query);
        }

        $this->assertOk($response);

        return $response->json() ?? [];
    }

    private function accessToken(): string
    {
        return Cache::remember('music:spotify-token', self::TOKEN_TTL, function () {
            $id = config('services.spotify.client_id');
            $secret = config('services.spotify.client_secret');

            if (! $id || ! $secret) {
                throw new RuntimeException('Spotify credentials are not configured (SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET).');
            }

            $response = Http::asForm()
                ->withBasicAuth($id, $secret)
                ->post(self::ACCOUNTS_URL.'/api/token', ['grant_type' => 'client_credentials']);

            if ($response->failed() || ! $response->json('access_token')) {
                throw new RuntimeException('Spotify token request failed: '.$response->body());
            }

            return $response->json('access_token');
        });
    }

    private function assertOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After') ?: '?';
            throw new RuntimeException("Spotify rate limit hit (Retry-After: {$retryAfter}s).");
        }

        // A 403 with an HTML body is Google's edge (GFE) rejecting the
        // request before Spotify sees it - usually a deprecated/blocked
        // endpoint or an app-level restriction, not a bad playlist.
        $body = $response->json('error.message')
            ?? (str_contains((string) $response->header('Content-Type'), 'html')
                ? 'blocked at the Spotify edge (deprecated endpoint or app restriction)'
                : \Illuminate\Support\Str::limit($response->body(), 200));

        throw new RuntimeException("Spotify request failed ({$response->status()}): {$body}");
    }
}
