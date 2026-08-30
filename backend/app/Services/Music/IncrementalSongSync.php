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
 * each call does a small, time-bounded slice of work (fetch one playlist's
 * track list, or seed a handful of tracks). Nothing depends on a queue
 * worker or the CLI - the whole run is observable and resumable from the
 * progress state cached under PROGRESS_KEY.
 *
 * The CLI `songs:sync` command still exists for the weekly schedule; both
 * write the shared "last sync" status (SyncSongsCommand::putStatus).
 */
class IncrementalSongSync
{
    public const PROGRESS_KEY = 'songs:sync-progress';

    /** Tracks seeded per step() call - each does an iTunes lookup + clip download. */
    private const SEED_BATCH = 4;

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
            'items' => [],
            'seen_ids' => [],
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
                $tracks = $this->spotify->playlistTracks($next['playlist_id']);
                $followers = $this->spotify->artistFollowerCounts(array_column($tracks, 'artist_provider_id'));

                foreach ($tracks as $track) {
                    $id = $track['provider_track_id'];

                    if (in_array($id, $state['seen_ids'], true)) {
                        continue;
                    }

                    $state['seen_ids'][] = $id;
                    $state['items'][] = [
                        'genre_tag' => $next['genre_tag'],
                        'track' => $track,
                        'follower' => $followers[$track['artist_provider_id'] ?? ''] ?? null,
                    ];
                }
            } catch (Throwable $e) {
                // One unreadable playlist (private, Spotify-editorial, or
                // an API hiccup) must not sink the whole run - note it and
                // carry on with the rest.
                $state['failed_playlists'][] = $next['playlist_id'];
            }

            $state['prepared_count']++;
        }

        if ($state['playlists'] === []) {
            $state['total_items'] = count($state['items']);
            $failed = $state['failed_playlists'];

            if ($state['items'] === []) {
                $state['phase'] = 'error';
                $state['error'] = $failed === []
                    ? 'None of the configured playlists returned any tracks.'
                    : count($failed).' playlist(s) could not be read (private, or a Spotify-made '
                        .'editorial playlist - use a public playlist created by a normal user): '
                        .implode(', ', $failed);
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
            $song = $this->seeder->persist($item['track'], $item['genre_tag'], $item['follower']);
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
