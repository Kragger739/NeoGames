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

    /** Tracks resolved+seeded per step() - each is a search + iTunes lookup + clip download. */
    private const SEED_BATCH = 3;

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
            'seen_keys' => [],   // "artist|title" dedup
            'total_items' => 0,
            'seeded' => 0,
            'skipped' => 0,
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
            $summary = "{$state['seeded']} seeded, {$state['skipped']} skipped for no preview";

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
            $state['total_items'] = count($state['items']);

            if ($state['items'] === []) {
                $failed = $state['failed_playlists'];
                $state['phase'] = 'error';
                $state['error'] = $failed === []
                    ? 'None of the configured playlists returned any tracks.'
                    : count($failed).' playlist(s) could not be read - make sure they are public and '
                        .'not a Spotify-made editorial playlist: '.implode(', ', $failed);
            } else {
                $state['phase'] = 'seed';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function seedBatch(array &$state): void
    {
        for ($i = 0; $i < self::SEED_BATCH && $state['items'] !== []; $i++) {
            $item = array_shift($state['items']);
            $track = $this->spotify->resolveTrack($item['title'], $item['artist']);
            $song = $this->seeder->persist($track, $item['genre_tag'], null);
            $song ? $state['seeded']++ : $state['skipped']++;
        }

        if ($state['items'] === []) {
            $state['phase'] = 'done';
        }
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
            'total_items' => $state['total_items'] ?? 0,
            'failed_playlists' => array_values($state['failed_playlists'] ?? []),
            'error' => $state['error'] ?? null,
            'summary' => $state['summary'] ?? null,
            'pool_size' => Song::count(),
        ];
    }
}
