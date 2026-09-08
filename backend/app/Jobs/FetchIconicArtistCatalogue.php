<?php

namespace App\Jobs;

use App\Models\IconicArtist;
use App\Models\IconicArtistSong;
use App\Services\Music\RateLimitException;
use App\Services\Music\SongPoolSeeder;
use App\Services\Music\SpotifyClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Crawls an Iconic Artist's Spotify catalogue (~100 tracks), ranks them by
 * popularity, and resolves an iTunes preview for each - top 20 first.
 *
 * Runs on the same single queue worker that advances live game rounds, so it
 * NEVER does the whole ~10-minute job in one go: each invocation does a
 * bounded slice (~a few HTTP calls, or 5 rate-limited iTunes lookups ≈ 16s)
 * then re-dispatches itself with a short gap. `$tries = 1` - every failure is
 * caught here and turned into either a re-dispatch or `fetch_status=failed`.
 * A Re-fetch bumps `iconic_artists.fetch_run_token`; a stale chain sees the
 * mismatch on its next slice and stops.
 */
class FetchIconicArtistCatalogue implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    private const ALBUMS_PER_SLICE = 10;

    private const CANDIDATE_CAP = 120;

    private const SEED_PER_SLICE = 5;

    private const GAP_SECONDS = 6;

    private const MAX_RL_STRIKES = 5;

    public function __construct(public int $iconicArtistId, public string $runToken) {}

    public function handle(SpotifyClient $spotify, SongPoolSeeder $seeder): void
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
            $done = match ($artist->fetch_status) {
                'pending', 'discovering' => $this->discoverSlice($artist, $spotify),
                'seeding' => $this->seedSlice($artist, $seeder),
                default => true,
            };

            if (! $done) {
                self::dispatch($this->iconicArtistId, $this->runToken)
                    ->delay(now()->addSeconds(self::GAP_SECONDS));
            }
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
     * Resolve the artist id, then crawl albums -> album tracks a bounded
     * batch at a time. When the album list is exhausted, hydrate full track
     * objects, de-dupe by title, rank by popularity, and upsert the rows.
     *
     * @return bool true when the whole phase is complete (or failed)
     */
    private function discoverSlice(IconicArtist $artist, SpotifyClient $spotify): bool
    {
        $cursor = $artist->fetch_cursor ?? [];

        if (($cursor['stage'] ?? null) !== 'albums') {
            $artistId = $this->resolveArtistId($artist->name, $spotify);

            if ($artistId === null) {
                $artist->forceFill([
                    'fetch_status' => 'failed',
                    'fetch_error' => "Spotify couldn't find an artist called \"{$artist->name}\".",
                    'fetch_cursor' => null,
                ])->save();

                return true;
            }

            $cursor = [
                'stage' => 'albums',
                'artist_id' => $artistId,
                'album_ids' => array_column($spotify->artistAlbums($artistId), 'id'),
                'candidate_ids' => [],
                'rl_strikes' => 0,
            ];

            $artist->forceFill(['fetch_status' => 'discovering', 'fetch_cursor' => $cursor])->save();
        }

        $batch = array_splice($cursor['album_ids'], 0, self::ALBUMS_PER_SLICE);

        foreach ($batch as $albumId) {
            foreach ($spotify->albumTracks($albumId) as $track) {
                if (! in_array($cursor['artist_id'], $track['artist_ids'], true)) {
                    continue;
                }

                $cursor['candidate_ids'][$track['id']] = true;
            }
        }

        if ($cursor['album_ids'] !== []) {
            $artist->forceFill(['fetch_cursor' => $cursor])->save();

            return false;
        }

        $tracks = $spotify->tracksByIds(array_keys($cursor['candidate_ids']));
        $tracks = $this->dedupeByTitle($tracks);
        usort($tracks, fn ($a, $b) => $b['popularity'] <=> $a['popularity']);
        $tracks = array_slice($tracks, 0, self::CANDIDATE_CAP);

        $this->upsertRows($artist, $tracks);

        $artist->forceFill([
            'fetch_status' => 'seeding',
            'fetched_total' => count($tracks),
            'fetch_error' => null,
            'fetch_cursor' => ['stage' => 'seeding', 'rl_strikes' => 0],
        ])->save();

        return false;
    }

    /**
     * Resolve iTunes previews for the next few un-seeded rows, lowest `rank`
     * (the hits) first. Blocking `usleep` between rows is the deliberate pace.
     *
     * @return bool true when every row has been tried
     */
    private function seedSlice(IconicArtist $artist, SongPoolSeeder $seeder): bool
    {
        $rows = IconicArtistSong::query()
            ->where('iconic_artist_id', $artist->id)
            ->whereNull('song_id')
            ->where('unplayable', false)
            ->whereNotNull('rank')
            ->orderBy('rank')
            ->limit(self::SEED_PER_SLICE)
            ->get();

        if ($rows->isEmpty()) {
            return $this->finishSeeding($artist);
        }

        $throttleMs = (int) config('music.itunes_throttle_ms', 3200);

        foreach ($rows as $i => $row) {
            // persist() calls AppleMusicClient::findPreview(); a
            // RateLimitException bubbles to handle()'s catch. Rows resolved
            // earlier in this loop are already saved, so the chain resumes
            // cleanly via whereNull('song_id').
            $song = $seeder->persist([
                'provider_track_id' => $row->provider_track_id,
                'isrc' => null,
                'title' => $row->title,
                'artist' => $row->artist,
                'artist_provider_id' => null,
                'album_art_url' => $row->album_art_url,
                'popularity' => $row->popularity,
                'release_year' => $row->release_year,
            ], genreTag: null, followerCount: null);

            if ($song) {
                $row->forceFill([
                    'song_id' => $song->id,
                    'preview_url' => $song->preview_url,
                    'unplayable' => false,
                ])->save();
            } else {
                $row->forceFill(['unplayable' => true])->save();
            }

            if ($i < $rows->count() - 1 && $throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
        }

        $this->clearStrikes($artist);
        $artist->forceFill(['fetched_playable' => $this->playableCount($artist)])->save();

        $more = IconicArtistSong::query()
            ->where('iconic_artist_id', $artist->id)
            ->whereNull('song_id')
            ->where('unplayable', false)
            ->whereNotNull('rank')
            ->exists();

        return $more ? false : $this->finishSeeding($artist);
    }

    private function resolveArtistId(string $name, SpotifyClient $spotify): ?string
    {
        return Cache::remember(
            'music:artist-id:'.mb_strtolower(trim($name)),
            now()->addDays(30),
            fn () => $spotify->findArtistId($name),
        );
    }

    /**
     * Keep one row per normalized title (drops "- Remastered 2011", live
     * versions, etc.), preferring the higher-popularity pressing.
     *
     * @param  array<int, array<string, mixed>>  $tracks
     * @return array<int, array<string, mixed>>
     */
    private function dedupeByTitle(array $tracks): array
    {
        $byKey = [];

        foreach ($tracks as $track) {
            $key = $this->normalizeTitle((string) $track['title']);

            if (! isset($byKey[$key]) || $track['popularity'] > $byKey[$key]['popularity']) {
                $byKey[$key] = $track;
            }
        }

        return array_values($byKey);
    }

    private function normalizeTitle(string $title): string
    {
        $title = mb_strtolower($title);
        $title = preg_replace('/\(.*?\)|\[.*?\]/u', ' ', $title);
        $title = preg_replace('/\b(feat|ft|featuring|with)\b.*/u', ' ', $title);
        $title = preg_replace('/\b(remaster(ed)?|live|deluxe|mono|stereo|re-?recorded|version|edit|radio)\b.*/u', ' ', $title);
        $title = preg_replace('/[^a-z0-9]+/u', ' ', $title);

        return trim(preg_replace('/\s+/', ' ', (string) $title));
    }

    /**
     * @param  array<int, array<string, mixed>>  $tracks  ranked, popularity desc
     */
    private function upsertRows(IconicArtist $artist, array $tracks): void
    {
        $rank = 0;

        foreach ($tracks as $track) {
            $rank++;

            IconicArtistSong::updateOrCreate(
                ['iconic_artist_id' => $artist->id, 'provider_track_id' => $track['provider_track_id']],
                [
                    'title' => $track['title'],
                    'artist' => $track['artist'],
                    'album_art_url' => $track['album_art_url'],
                    'release_year' => $track['release_year'],
                    'popularity' => $track['popularity'],
                    'rank' => $rank,
                    // song_id / preview_url / unplayable left alone so a
                    // Re-fetch keeps rows that already resolved.
                ],
            );
        }

        // Rows from a prior run that dropped out of the new top set: null
        // their rank so selection's whereNotNull('rank') gate skips them.
        IconicArtistSong::query()
            ->where('iconic_artist_id', $artist->id)
            ->whereNotIn('provider_track_id', array_column($tracks, 'provider_track_id'))
            ->update(['rank' => null]);
    }

    private function finishSeeding(IconicArtist $artist): bool
    {
        $artist->forceFill([
            'fetch_status' => 'done',
            'fetched_playable' => $this->playableCount($artist),
            'fetched_at' => now(),
            'fetch_error' => null,
            'fetch_cursor' => null,
        ])->save();

        return true;
    }

    private function playableCount(IconicArtist $artist): int
    {
        return IconicArtistSong::query()
            ->where('iconic_artist_id', $artist->id)
            ->whereNotNull('song_id')
            ->where('unplayable', false)
            ->count();
    }

    private function clearStrikes(IconicArtist $artist): void
    {
        $cursor = $artist->fetch_cursor ?? [];
        $cursor['rl_strikes'] = 0;
        $artist->forceFill(['fetch_cursor' => $cursor])->save();
    }

    private function onRateLimit(IconicArtist $artist): void
    {
        $cursor = $artist->fetch_cursor ?? [];
        $strikes = ($cursor['rl_strikes'] ?? 0) + 1;

        if ($strikes >= self::MAX_RL_STRIKES) {
            $artist->forceFill([
                'fetch_status' => 'failed',
                'fetch_error' => 'Spotify / iTunes kept rate-limiting the server. Hit Re-fetch in ~15 minutes to resume.',
            ])->save();

            return;
        }

        $cursor['rl_strikes'] = $strikes;
        $artist->forceFill(['fetch_cursor' => $cursor])->save();

        self::dispatch($this->iconicArtistId, $this->runToken)
            ->delay(now()->addSeconds(min(60 * $strikes, 300)));
    }
}
