<?php

namespace App\Services\Music;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

// RateLimitException lives in this namespace.

/**
 * Apple's iTunes Search API - no auth, no key. Its only job here is to hand
 * back a playable 30-second preview (and cover art) for a track that Spotify
 * has already identified: Spotify no longer serves preview audio, and iTunes
 * previews are stable, unsigned URLs that don't expire (unlike the old
 * Deezer signed links, which is what made the whole live-refresh dance
 * necessary before).
 *
 * There is no id or ISRC bridge between the two catalogues, so the match is
 * a text search on "<artist> <title>" filtered back down to a confident
 * artist+title agreement. Called only by `songs:sync` and Artist-pool
 * priming - never in a round's request path - and the caller is expected to
 * throttle (the API rate-limits around 20 req/min).
 */
class AppleMusicClient
{
    private const BASE_URL = 'https://itunes.apple.com';

    /**
     * @return array{itunes_track_id: string, preview_url: string, album_art_url: ?string, release_year: ?int}|null
     */
    public function findPreview(string $artist, string $title): ?array
    {
        $response = Http::acceptJson()->get(self::BASE_URL.'/search', [
            'term' => trim($artist.' '.$title),
            'entity' => 'song',
            'limit' => 5,
            'country' => config('music.itunes_country', 'US'),
        ]);

        if ($response->status() === 403 || $response->status() === 429) {
            throw new RateLimitException('iTunes Search API rate limit hit (HTTP '.$response->status().').');
        }

        if ($response->failed()) {
            throw new RuntimeException("iTunes request failed ({$response->status()}): ".$response->body());
        }

        $wantArtist = $this->normalize($artist);
        $wantTitle = $this->normalize($title);

        $best = null;
        $bestScore = 0;

        foreach ($response->json('results', []) as $result) {
            if (($result['kind'] ?? null) !== 'song' || empty($result['previewUrl'])) {
                continue;
            }

            $score = $this->matchScore(
                $wantArtist,
                $wantTitle,
                $this->normalize($result['artistName'] ?? ''),
                $this->normalize($result['trackName'] ?? ''),
            );

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $result;
            }
        }

        // 3 = artist agrees AND titles are at least a prefix match. Anything
        // weaker isn't worth seeding a wrong clip for.
        if ($best === null || $bestScore < 3) {
            return null;
        }

        return [
            'itunes_track_id' => (string) $best['trackId'],
            'preview_url' => $best['previewUrl'],
            'album_art_url' => $this->upscaleArtwork($best['artworkUrl100'] ?? null),
            'release_year' => $this->parseReleaseYear($best['releaseDate'] ?? null),
        ];
    }

    private function matchScore(string $wantArtist, string $wantTitle, string $gotArtist, string $gotTitle): int
    {
        $score = 0;

        if ($gotArtist !== '' && ($gotArtist === $wantArtist
            || str_contains($gotArtist, $wantArtist)
            || str_contains($wantArtist, $gotArtist))) {
            $score += 2;
        }

        if ($gotTitle !== '' && $gotTitle === $wantTitle) {
            $score += 2;
        } elseif ($gotTitle !== '' && (str_starts_with($gotTitle, $wantTitle) || str_starts_with($wantTitle, $gotTitle))) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Lowercase, drop bracketed segments and everything from "feat"/"ft"
     * onward, then reduce to single-spaced alphanumerics. "Beyoncé (feat.
     * JAY-Z) [Remix]" -> "beyonce".
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/\(.*?\)|\[.*?\]/u', ' ', $value);
        $value = preg_replace('/\b(feat|ft|featuring|with)\b.*/u', ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function upscaleArtwork(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return preg_replace('/\/\d+x\d+bb\.(jpg|png)$/', '/600x600bb.$1', $url);
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
}
