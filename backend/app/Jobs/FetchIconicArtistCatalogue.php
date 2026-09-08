<?php

namespace App\Jobs;

use App\Models\IconicArtist;
use App\Models\IconicArtistSong;
use App\Models\Song;
use App\Services\Music\AppleMusicClient;
use App\Services\Music\RateLimitException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds an Iconic Artist's curated catalogue straight from the iTunes Search
 * API - one call gets the artist's songs (in Apple's relevance order, a
 * popularity proxy) with preview URLs already attached, so there's no Spotify
 * crawl (edge-blocked for our app token) and no per-track preview lookup. The
 * whole thing is a single quick pass; games play `rank <= 20`.
 *
 * `$tries = 1` - every failure is caught here and turned into either a
 * re-dispatch (rate limit) or `fetch_status = failed`. A Re-fetch bumps
 * `iconic_artists.fetch_run_token`; a stale run sees the mismatch and stops.
 */
class FetchIconicArtistCatalogue implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    private const CANDIDATE_CAP = 120;

    private const MAX_RL_STRIKES = 5;

    public function __construct(public int $iconicArtistId, public string $runToken) {}

    public function handle(AppleMusicClient $apple): void
    {
        $artist = IconicArtist::find($this->iconicArtistId);

        // Deleted, superseded by a newer Re-fetch, or already settled.
        if (! $artist || $artist->fetch_run_token !== $this->runToken) {
            return;
        }

        if (in_array($artist->fetch_status, ['done', 'failed'], true)) {
            return;
        }

        try {
            $artist->forceFill(['fetch_status' => 'discovering', 'fetch_error' => null])->save();

            $ranked = $this->collect($artist, $apple);

            if ($ranked === []) {
                $artist->forceFill([
                    'fetch_status' => 'failed',
                    'fetch_error' => "iTunes returned no songs for \"{$artist->name}\".",
                    'fetch_cursor' => null,
                ])->save();

                return;
            }

            $this->writeRows($artist, $ranked);

            $artist->forceFill([
                'fetch_status' => 'done',
                'fetched_total' => count($ranked),
                'fetched_playable' => count($ranked),
                'fetched_at' => now(),
                'fetch_error' => null,
                'fetch_cursor' => null,
            ])->save();
        } catch (RateLimitException) {
            $this->onRateLimit($artist);
        } catch (Throwable $e) {
            $artist->forceFill([
                'fetch_status' => 'failed',
                'fetch_error' => Str::limit($e->getMessage(), 240),
                'fetch_cursor' => null,
            ])->save();

            report($e);
        }
    }

    /**
     * iTunes songs for the artist, de-duplicated by normalized title (keeping
     * the more relevant pressing), capped and returned in relevance = rank
     * order.
     *
     * If the artist has a pinned `apple_artist_id` (admin pasted their Apple
     * Music link), results are locked to that exact artist id - the name
     * search only decides ordering, not identity. Otherwise it falls back to
     * the exact-name string filter.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collect(IconicArtist $artist, AppleMusicClient $apple): array
    {
        $name = $artist->name;
        $pinnedId = $artist->apple_artist_id;
        $wanted = mb_strtolower(trim($name));
        $seen = [];
        $ranked = [];

        $songs = $apple->artistSongs($name, self::CANDIDATE_CAP + 60, $pinnedId);

        // A badly misspelled name can return nothing from the artistTerm
        // search even with a valid pinned id - fall back to the id lookup.
        if ($songs === [] && $pinnedId !== null) {
            $songs = $apple->artistSongsById($pinnedId, self::CANDIDATE_CAP + 60);
        }

        foreach ($songs as $song) {
            if ($pinnedId === null && mb_strtolower(trim($song['artist'])) !== $wanted) {
                continue;
            }

            $key = $this->normalizeTitle($song['title']);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $ranked[] = $song;

            if (count($ranked) >= self::CANDIDATE_CAP) {
                break;
            }
        }

        return $ranked;
    }

    /**
     * @param  array<int, array<string, mixed>>  $ranked  relevance order
     */
    private function writeRows(IconicArtist $artist, array $ranked): void
    {
        $providerIds = [];

        foreach ($ranked as $i => $track) {
            $rank = $i + 1;
            // No true popularity from iTunes - synthesize one from rank so the
            // shared `songs` pool (sorted by popularity elsewhere) stays sane.
            $popularity = max(1, 101 - $rank);
            $providerTrackId = 'itunes:'.$track['itunes_track_id'];

            $song = Song::updateOrCreate(
                ['provider_track_id' => $providerTrackId],
                [
                    'title' => $track['title'],
                    'artist' => $track['artist'],
                    'artist_provider_id' => null,
                    'preview_url' => $track['preview_url'],
                    'album_art_url' => $track['album_art_url'],
                    'popularity' => $popularity,
                    'release_year' => $track['release_year'],
                ],
            );

            IconicArtistSong::updateOrCreate(
                ['iconic_artist_id' => $artist->id, 'provider_track_id' => $providerTrackId],
                [
                    'title' => $track['title'],
                    'artist' => $track['artist'],
                    'album_art_url' => $track['album_art_url'],
                    'release_year' => $track['release_year'],
                    'popularity' => $popularity,
                    'rank' => $rank,
                    'preview_url' => $track['preview_url'],
                    'song_id' => $song->id,
                    'unplayable' => false,
                ],
            );

            $providerIds[] = $providerTrackId;
        }

        // Rows from a prior run that dropped out of the new set: demote them
        // so selection's whereNotNull('rank') gate skips them.
        IconicArtistSong::query()
            ->where('iconic_artist_id', $artist->id)
            ->whereNotIn('provider_track_id', $providerIds)
            ->update(['rank' => null, 'unplayable' => true]);
    }

    /**
     * Collapse "Song", "Song (Remastered 2011)", "Song - Live at Wembley",
     * "Song (feat. X)" to the same key - without mangling titles that
     * legitimately contain words like "Radio", "Live" or "Version" (only a
     * bracketed segment or a " - " suffix is treated as a qualifier).
     */
    private function normalizeTitle(string $title): string
    {
        $title = mb_strtolower($title);
        $title = preg_replace('/\(.*?\)|\[.*?\]/u', ' ', $title);
        $title = preg_split('/\s+-\s+/u', $title, 2)[0] ?? $title;
        $title = preg_replace('/\b(feat\.?|ft\.?|featuring)\b.*/u', ' ', $title);
        $title = preg_replace('/[^a-z0-9]+/u', ' ', $title);

        return trim(preg_replace('/\s+/', ' ', (string) $title));
    }

    private function onRateLimit(IconicArtist $artist): void
    {
        $cursor = $artist->fetch_cursor ?? [];
        $strikes = ($cursor['rl_strikes'] ?? 0) + 1;

        if ($strikes >= self::MAX_RL_STRIKES) {
            $artist->forceFill([
                'fetch_status' => 'failed',
                'fetch_error' => 'iTunes kept rate-limiting the server. Hit Re-fetch in ~15 minutes.',
            ])->save();

            return;
        }

        $artist->forceFill([
            'fetch_status' => 'pending',
            'fetch_cursor' => ['rl_strikes' => $strikes],
        ])->save();

        self::dispatch($this->iconicArtistId, $this->runToken)
            ->delay(now()->addSeconds(min(60 * $strikes, 300)));
    }
}
