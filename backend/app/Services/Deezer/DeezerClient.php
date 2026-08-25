<?php

namespace App\Services\Deezer;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Replaces ITunesClient + LastFmClient entirely. No auth required, plain
 * HTTP GET. Deezer supplies both a real, playable preview_url (like iTunes
 * did) and a popularity signal (rank) directly inline on search/chart
 * results (unlike iTunes, which had neither) - so unlike the old two-hop
 * iTunes-then-Last.fm design, most paths here need only one request per
 * candidate. The one unavoidable second hop is trackDetails(): release date
 * is confirmed (by direct testing) to exist only on GET /track/{id}, never
 * on search/chart/playlist results.
 *
 * Deezer returns HTTP 200 with a JSON {"error": {...}} body on failures
 * (including rate limiting) rather than a 4xx/429 status, so every call
 * must check the body explicitly - Http::failed() alone won't catch it.
 */
class DeezerClient
{
    private const BASE_URL = 'https://api.deezer.com';

    /** Deezer's `rank` field is uncapped in principle; scores are clamped to this before scaling. */
    public const MAX_RANK = 100_000;

    public const GENRE_ID_POP = 132;

    public const GENRE_ID_HIP_HOP = 116;

    /** How many /search/artist results to consider when resolving a name - see findArtistId(). */
    private const ARTIST_SEARCH_LIMIT = 25;

    /**
     * @return array<int, array{deezer_track_id: string, title: string, artist: string, artist_deezer_id: ?string, album_art_url: ?string, preview_url: ?string, popularity: int}>
     */
    public function search(string $query, int $limit = 10): array
    {
        $response = Http::get(self::BASE_URL.'/search', [
            'q' => $query,
            'limit' => $limit,
        ]);

        $this->assertNoApiError($response);

        return array_map(
            fn (array $track) => $this->normalizeTrack($track),
            $response->json('data', []),
        );
    }

    /**
     * The only endpoint confirmed to carry release_date - search/chart/
     * playlist results never do.
     *
     * @return array{deezer_track_id: string, title: string, artist: string, artist_deezer_id: ?string, album_art_url: ?string, preview_url: ?string, popularity: int, release_year: ?int}|null
     */
    public function trackDetails(string $deezerTrackId): ?array
    {
        $response = Http::get(self::BASE_URL."/track/{$deezerTrackId}");

        if ($response->failed()) {
            throw new RuntimeException('Deezer request failed: '.$response->body());
        }

        // An unknown track ID comes back as HTTP 200 with a JSON error body
        // (e.g. a "no data" DataException), not a 404 - that's a legitimate
        // "not found" here, distinct from assertNoApiError()'s callers,
        // which treat any error body as a hard failure. Rate limiting still
        // needs to surface as an exception rather than a false "not found".
        $error = $response->json('error');

        if ($error !== null) {
            if (($error['code'] ?? null) === 4) {
                throw new RuntimeException('Deezer rate limit exceeded: '.$response->body());
            }

            return null;
        }

        $track = $response->json();

        if ($track === null || ! isset($track['id'])) {
            return null;
        }

        return [
            ...$this->normalizeTrack($track),
            'release_year' => $this->parseReleaseYear($track['release_date'] ?? null),
        ];
    }

    /**
     * GET /chart/{genreId}/tracks, or the un-scoped GET /chart/0/tracks when
     * $genreId is null. Note: the un-scoped chart is confirmed (by direct
     * testing) to be implicitly localized to the calling server's own IP -
     * it is not a neutral "global" chart.
     *
     * @return array<int, array{deezer_track_id: string, title: string, artist: string, artist_deezer_id: ?string, album_art_url: ?string, preview_url: ?string, popularity: int}>
     */
    public function chart(?int $genreId, int $limit = 50): array
    {
        $response = Http::get(self::BASE_URL.'/chart/'.($genreId ?? 0).'/tracks', [
            'limit' => $limit,
        ]);

        $this->assertNoApiError($response);

        return array_map(
            fn (array $track) => $this->normalizeTrack($track),
            $response->json('data', []),
        );
    }

    /**
     * Resolves an artist's display name to their Deezer artist id -
     * deliberately not just "take the first /search/artist result": Deezer's
     * search relevance does NOT rank by fame (confirmed live - searching
     * "Drake" returns two 76/95-fan decoys literally also named Drake
     * *before* the real one, a 24-million-fan artist, which only shows up
     * several results down). Instead: fetch a batch of results, keep only
     * the ones whose name is an exact case-insensitive match to the query
     * (ruling out a same-fame-tier but differently-named artist, e.g. "Dido"
     * outranking "Sido" by fan count - confirmed live this would otherwise
     * pick the wrong person entirely), then take the highest nb_fan among
     * those exact matches. Falls back to the highest nb_fan among every
     * result if nothing matches exactly (a typo/near-miss name still
     * resolves to something reasonable rather than nothing). Null only if
     * Deezer returns zero results at all.
     */
    public function findArtistId(string $name): ?string
    {
        $response = Http::get(self::BASE_URL.'/search/artist', [
            'q' => $name,
            'limit' => self::ARTIST_SEARCH_LIMIT,
        ]);

        $this->assertNoApiError($response);

        $results = $response->json('data', []);

        if ($results === []) {
            return null;
        }

        $normalizedQuery = mb_strtolower(trim($name));
        $exactMatches = array_filter(
            $results,
            fn (array $artist) => mb_strtolower(trim($artist['name'])) === $normalizedQuery,
        );

        $candidates = $exactMatches !== [] ? $exactMatches : $results;

        usort($candidates, fn (array $a, array $b) => ($b['nb_fan'] ?? 0) <=> ($a['nb_fan'] ?? 0));

        return (string) $candidates[array_key_first($candidates)]['id'];
    }

    /**
     * GET /artist/{id}/top - the artist's own top tracks, same normalized
     * shape as chart()/search() (confirmed live - identical track JSON).
     * Used for the Artist genre, which sources exclusively from one act's
     * catalog rather than any chart or generic search.
     *
     * @return array<int, array{deezer_track_id: string, title: string, artist: string, artist_deezer_id: ?string, album_art_url: ?string, preview_url: ?string, popularity: int}>
     */
    public function artistTopTracks(string $artistId, int $limit = 50): array
    {
        $response = Http::get(self::BASE_URL."/artist/{$artistId}/top", [
            'limit' => $limit,
        ]);

        $this->assertNoApiError($response);

        return array_map(
            fn (array $track) => $this->normalizeTrack($track),
            $response->json('data', []),
        );
    }

    /**
     * @return array{deezer_track_id: string, title: string, artist: string, artist_deezer_id: ?string, album_art_url: ?string, preview_url: ?string, popularity: int}
     */
    private function normalizeTrack(array $track): array
    {
        return [
            'deezer_track_id' => (string) $track['id'],
            'title' => $track['title'],
            'artist' => $track['artist']['name'],
            // Only the id, not the artist's fan count - that's not on the
            // track response at all, see artistFanCount() below.
            'artist_deezer_id' => isset($track['artist']['id']) ? (string) $track['artist']['id'] : null,
            'album_art_url' => $track['album']['cover_medium'] ?? null,
            'preview_url' => $track['preview'] ?: null,
            'popularity' => $this->rankPopularity((int) ($track['rank'] ?? 0)),
        ];
    }

    /**
     * Deezer has no per-track play/fan count, only this per-artist figure
     * (the closest real, non-fabricated "how popular is this" number for
     * the reveal screen). Null if the artist can't be found or the field
     * is missing - deliberately not defaulted to 0, which would look like
     * a real (if tiny) stat rather than "unknown".
     */
    public function artistFanCount(string $artistDeezerId): ?int
    {
        $response = Http::get(self::BASE_URL."/artist/{$artistDeezerId}");

        if ($response->failed()) {
            throw new RuntimeException('Deezer request failed: '.$response->body());
        }

        $error = $response->json('error');

        if ($error !== null) {
            if (($error['code'] ?? null) === 4) {
                throw new RuntimeException('Deezer rate limit exceeded: '.$response->body());
            }

            return null;
        }

        $fanCount = $response->json('nb_fan');

        return $fanCount === null ? null : (int) $fanCount;
    }

    private function rankPopularity(int $rank): int
    {
        $clamped = min(max($rank, 0), self::MAX_RANK);

        return (int) round(($clamped / self::MAX_RANK) * 100);
    }

    private function parseReleaseYear(?string $releaseDate): ?int
    {
        if (! $releaseDate) {
            return null;
        }

        try {
            return Carbon::parse($releaseDate)->year;
        } catch (Throwable) {
            return null;
        }
    }

    private function assertNoApiError(\Illuminate\Http\Client\Response $response): void
    {
        if ($response->failed()) {
            throw new RuntimeException('Deezer request failed: '.$response->body());
        }

        $error = $response->json('error');

        if ($error !== null) {
            $code = $error['code'] ?? null;

            if ($code === 4) {
                throw new RuntimeException('Deezer rate limit exceeded: '.$response->body());
            }

            throw new RuntimeException('Deezer API error: '.$response->body());
        }
    }
}
