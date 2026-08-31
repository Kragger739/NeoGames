<?php

namespace App\Services\Music;

use App\Console\Commands\SyncSongsCommand;
use App\Models\SeedPlaylist;
use App\Models\Song;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Browser-driven song sync: the admin dashboard calls step() repeatedly, and
 * each call does a small, time-bounded slice of work - scrape one playlist's
 * track list from its public Spotify page, or resolve + seed a few tracks.
 * Nothing depends on a queue worker or the CLI, and nothing depends on the
 * Spotify playlist API (403-blocked for app tokens): the track list comes
 * from the public embed page, popularity from a per-track search (best
 * effort), and the preview from Apple. The run is resumable from the
 * progress state cached under PROGRESS_KEY.
 *
 * The CLI `songs:sync` command still exists for the weekly schedule; both
 * write the shared "last sync" status (SyncSongsCommand::putStatus).
 */
class IncrementalSongSync
{
    public const PROGRESS_KEY = 'songs:sync-progress';

    /**
     * Tracks resolved+seeded per step() - each is a search + iTunes lookup +
     * clip download. One per step so the seed phase can pace itself: iTunes
     * rate-limits around 20 req/min, so a burst of back-to-back lookups just
     * gets 403'd.
     */
    private const SEED_BATCH = 1;

    /** Consecutive rate-limit pauses with no forward progress before we give up. */
    private const MAX_RL_STRIKES = 5;

    /** Rate-limit retries on a single track before we drop it and move on. */
    private const PER_TRACK_RL_CAP = 3;

    public function __construct(
        private SpotifyClient $spotify,
        private SongPoolSeeder $seeder,
    ) {}

    /**
     * Begin a fresh run. `$fresh` wipes the pool + cached clips first.
     *
     * @return array<string, mixed>
     */
    public function start(bool $fresh = false): array
    {
        if (! config('services.spotify.client_id') || ! config('services.spotify.client_secret')) {
            return $this->settle('error', 'Spotify API credentials are not configured on the server.');
        }

        $playlists = SeedPlaylist::query()->orderBy('genre')->orderBy('id')->get()
            ->map(fn (SeedPlaylist $p) => [
                'genre_tag' => $p->genre->cacheTag(),
                'playlist_id' => $p->spotify_playlist_id,
            ])->all();

        if ($playlists === []) {
            return $this->settle('error', 'No playlists configured - add at least one above, then sync again.');
        }

        if ($fresh) {
            Song::query()->delete();
            Storage::disk('public')->deleteDirectory('song-previews');
        }

        $state = [
            'phase' => 'prepare',
            'playlists' => $playlists,
            'prepared_count' => 0,
            'total_playlists' => count($playlists),
            'failed_playlists' => [],
            'items' => [],       // [{genre_tag, title, artist}]
            'seen_keys' => [],   // "artist|title" dedup within this run
            'total_items' => 0,
            'seeded' => 0,
            'skipped' => 0,
            'already' => 0,      // already in the pool before this run
            'rate_limited_until' => null,
            'throttle_until' => null,  // gentle pacing between iTunes lookups
            'rl_strikes' => 0,        // consecutive rate-limit pauses since last progress
            'started_at' => now()->toIso8601String(),
            'error' => null,
            'summary' => null,
        ];

        Cache::put(self::PROGRESS_KEY, $state, now()->addHours(2));
        SyncSongsCommand::putStatus('running');

        return $this->clientState($state);
    }

    /**
     * Advance the run by one slice. Safe to call when nothing is running.
     *
     * @return array<string, mixed>
     */
    public function step(): array
    {
        $state = Cache::get(self::PROGRESS_KEY);

        if (! is_array($state) || in_array($state['phase'], ['done', 'error'], true)) {
            return $this->clientState($state ?: ['phase' => 'idle']);
        }

        // Still cooling down from a rate limit - do nothing, let the client
        // keep waiting (it also honours rate_limited_until).
        if (($state['rate_limited_until'] ?? null) && time() < $state['rate_limited_until']) {
            return $this->clientState($state);
        }
        $state['rate_limited_until'] = null;

        // Pacing between iTunes lookups - the client keeps polling, we just do
        // nothing until the throttle window passes.
        if (($state['throttle_until'] ?? null) && time() < $state['throttle_until']) {
            return $this->clientState($state);
        }
        $state['throttle_until'] = null;

        try {
            match ($state['phase']) {
                'prepare' => $this->prepareOne($state),
                'seed' => $this->seedBatch($state),
                default => null,
            };
        } catch (Throwable $e) {
            $state['phase'] = 'error';
            $state['error'] = $e->getMessage();
        }

        if ($state['phase'] === 'done') {
            $summary = "{$state['seeded']} added, {$state['already']} already in pool, {$state['skipped']} skipped for no preview";

            if (($state['failed_playlists'] ?? []) !== []) {
                $summary .= '; '.count($state['failed_playlists']).' playlist(s) unreadable';
            }

            $state['summary'] = $summary;
            SyncSongsCommand::putStatus('done', $summary);
        } elseif ($state['phase'] === 'error') {
            SyncSongsCommand::putStatus('error', $state['error']);
        }

        Cache::put(self::PROGRESS_KEY, $state, now()->addHours(2));

        return $this->clientState($state);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        $state = Cache::get(self::PROGRESS_KEY);

        return is_array($state) ? $this->clientState($state) : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function prepareOne(array &$state): void
    {
        $next = array_shift($state['playlists']);

        if ($next !== null) {
            try {
                foreach ($this->spotify->scrapePlaylistItems($next['playlist_id']) as $row) {
                    $key = mb_strtolower(trim($row['artist']).'|'.trim($row['title']));

                    if ($row['title'] === '' || in_array($key, $state['seen_keys'], true)) {
                        continue;
                    }

                    $state['seen_keys'][] = $key;
                    $state['items'][] = [
                        'genre_tag' => $next['genre_tag'],
                        'title' => $row['title'],
                        'artist' => $row['artist'],
                    ];
                }
            } catch (Throwable $e) {
                // One unreadable playlist (private / removed / page changed)
                // must not sink the whole run - note it and carry on.
                $state['failed_playlists'][] = $next['playlist_id'];
            }

            $state['prepared_count']++;
        }

        if ($state['playlists'] === []) {
            if ($state['items'] === []) {
                $state['total_items'] = 0;
                $failed = $state['failed_playlists'];
                $state['phase'] = 'error';
                $state['error'] = $failed === []
                    ? 'None of the configured playlists returned any tracks.'
                    : count($failed).' playlist(s) could not be read - make sure they are public and '
                        .'not a Spotify-made editorial playlist: '.implode(', ', $failed);
            } else {
                // Drop everything already in the pool in one pass, so the seed
                // phase only touches genuinely new tracks (no per-track API
                // work, no per-track EXISTS query). seedBatch() keeps its own
                // alreadyInPool() check as a backstop.
                $this->filterOutPooled($state);
                $state['total_items'] = count($state['items']);
                $state['phase'] = 'seed';
            }
        }
    }

    /**
     * Remove candidate items that are already in the song pool, counting the
     * removed ones into `already`. Identity matches alreadyInPool(): the
     * synthetic scraped id, or a case-insensitive title + artist match.
     *
     * @param  array<string, mixed>  $state
     */
    private function filterOutPooled(array &$state): void
    {
        $pooledIds = [];
        $pooledKeys = [];

        foreach (Song::query()->select('provider_track_id', 'title', 'artist')->cursor() as $song) {
            $pooledIds[$song->provider_track_id] = true;
            $pooledKeys[mb_strtolower(trim($song->artist).'|'.trim($song->title))] = true;
        }

        if ($pooledIds === [] && $pooledKeys === []) {
            return;
        }

        $kept = [];

        foreach ($state['items'] as $item) {
            $key = mb_strtolower(trim($item['artist']).'|'.trim($item['title']));
            $scrapedId = SpotifyClient::scrapedId($item['artist'], $item['title']);

            if (isset($pooledIds[$scrapedId]) || isset($pooledKeys[$key])) {
                $state['already']++;

                continue;
            }

            $kept[] = $item;
        }

        $state['items'] = $kept;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function seedBatch(array &$state): void
    {
        for ($i = 0; $i < self::SEED_BATCH && $state['items'] !== []; $i++) {
            $item = array_shift($state['items']);

            // Already in the pool from an earlier run - skip the API work.
            if ($this->alreadyInPool($item['title'], $item['artist'])) {
                $state['already']++;

                continue;
            }

            try {
                $track = $this->spotify->resolveTrack($item['title'], $item['artist']);
                $song = $this->seeder->persist($track, $item['genre_tag'], null);
                $song ? $state['seeded']++ : $state['skipped']++;

                // Progress made - reset the strike count and pace the next
                // lookup so we stay under iTunes' ~20 req/min limit.
                $state['rl_strikes'] = 0;
                $state['throttle_until'] = time() + $this->throttleSeconds();
            } catch (RateLimitException) {
                $item['rl_attempts'] = ($item['rl_attempts'] ?? 0) + 1;
                $state['rl_strikes']++;

                // Repeatedly rate-limited with nothing getting through - stop
                // and let the admin resume later (seeded songs persist, so a
                // fresh run skips them via filterOutPooled()).
                if ($state['rl_strikes'] >= self::MAX_RL_STRIKES) {
                    $state['phase'] = 'error';
                    $state['error'] = sprintf(
                        'Spotify / iTunes kept rate-limiting this server (gave up after %d retries). '
                        .'%d songs added, %d left - wait ~15 minutes, then hit Sync again to pick up '
                        .'where it left off.',
                        $state['rl_strikes'],
                        $state['seeded'],
                        count($state['items']) + 1,
                    );

                    return;
                }

                // Drop a single track we keep failing to fetch; otherwise put
                // it back at the front and retry after the cooldown.
                if ($item['rl_attempts'] >= self::PER_TRACK_RL_CAP) {
                    $state['skipped']++;
                } else {
                    array_unshift($state['items'], $item);
                }

                $state['rate_limited_until'] = time() + min(60 * $state['rl_strikes'], 300);

                return;
            } catch (Throwable) {
                // A one-off failure (iTunes 5xx, a decode error) - skip this
                // track rather than sinking the whole run. Not a rate limit,
                // so clear the strike count and keep pacing.
                $state['skipped']++;
                $state['rl_strikes'] = 0;
                $state['throttle_until'] = time() + $this->throttleSeconds();
            }
        }

        if ($state['items'] === []) {
            $state['phase'] = 'done';
        }
    }

    /** Seconds to wait between iTunes lookups (from music.itunes_throttle_ms). */
    private function throttleSeconds(): int
    {
        return max(1, (int) ceil((int) config('music.itunes_throttle_ms', 3200) / 1000));
    }

    private function alreadyInPool(string $title, string $artist): bool
    {
        return Song::query()
            ->where('provider_track_id', SpotifyClient::scrapedId($artist, $title))
            ->orWhere(fn ($q) => $q
                ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($title))])
                ->whereRaw('LOWER(artist) = ?', [mb_strtolower(trim($artist))]))
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function settle(string $phase, string $message): array
    {
        Cache::forget(self::PROGRESS_KEY);
        SyncSongsCommand::putStatus($phase, $message);

        return ['phase' => $phase, 'error' => $phase === 'error' ? $message : null, 'summary' => $phase === 'done' ? $message : null];
    }

    /**
     * The client-facing slice of the state (no big internal arrays).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function clientState(array $state): array
    {
        return [
            'phase' => $state['phase'] ?? 'idle',
            'prepared_count' => $state['prepared_count'] ?? 0,
            'total_playlists' => $state['total_playlists'] ?? 0,
            'seeded' => $state['seeded'] ?? 0,
            'skipped' => $state['skipped'] ?? 0,
            'already' => $state['already'] ?? 0,
            'total_items' => $state['total_items'] ?? 0,
            'failed_playlists' => array_values($state['failed_playlists'] ?? []),
            'rate_limited_until' => $state['rate_limited_until'] ?? null,
            'throttle_until' => $state['throttle_until'] ?? null,
            'rl_strikes' => $state['rl_strikes'] ?? 0,
            'error' => $state['error'] ?? null,
            'summary' => $state['summary'] ?? null,
            'pool_size' => Song::count(),
        ];
    }
}
