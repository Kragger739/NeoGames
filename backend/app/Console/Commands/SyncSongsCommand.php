<?php

namespace App\Console\Commands;

use App\Enums\SongGenre;
use App\Models\SeedPlaylist;
use App\Models\Song;
use App\Services\Music\SongPoolSeeder;
use App\Services\Music\SpotifyClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Rebuilds the local `songs` pool from the curated Spotify playlists in the
 * seed_playlists table (managed from the admin dashboard), resolving each
 * track's 30-second preview through Apple's iTunes Search API and caching the
 * clip on the 'public' disk. This is the only thing that touches a music API
 * - game rounds read the pool directly. Run it from the admin "Sync now"
 * button, `php artisan songs:sync`, or the weekly schedule.
 *
 *   php artisan songs:sync                       # every configured genre
 *   php artisan songs:sync --genre=iconic --genre=pop
 *   php artisan songs:sync --fresh               # wipe pool + cached clips first
 */
class SyncSongsCommand extends Command
{
    protected $signature = 'songs:sync {--genre=* : Genre value(s) to sync; defaults to all configured} {--fresh : Delete the whole songs pool and cached preview clips before seeding}';

    protected $description = 'Seed the Songle song pool from the admin-managed Spotify playlists (+ cached iTunes previews)';

    public const STATUS_CACHE_KEY = 'songs:last-sync';

    public function handle(SpotifyClient $spotify, SongPoolSeeder $seeder): int
    {
        self::putStatus('running');

        if (! config('services.spotify.client_id') || ! config('services.spotify.client_secret')) {
            $this->error('SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET are not set.');
            self::putStatus('error', 'Spotify API credentials are not configured on the server.');

            return self::FAILURE;
        }

        $genres = $this->targetGenres();

        if ($genres === []) {
            $this->warn('No genres to sync - add Spotify playlists in the admin dashboard first.');
            self::putStatus('error', 'No playlists configured - add at least one above, then sync again.');

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $count = Song::query()->delete();
            Storage::disk('public')->deleteDirectory('song-previews');
            $this->line("Wiped {$count} existing rows and cached clips.");
        }

        $throttle = (int) config('music.itunes_throttle_ms', 3200);
        $seeded = 0;
        $skipped = 0;

        foreach ($genres as $genre) {
            $this->info("── {$genre->value} ──");

            foreach (SeedPlaylist::idsFor($genre) as $playlistId) {
                try {
                    $result = $seeder->seedPlaylist(
                        $playlistId,
                        $genre->cacheTag(),
                        $throttle,
                        fn (string $line) => $this->line($line),
                    );
                } catch (RuntimeException $e) {
                    $this->error("  playlist {$playlistId}: {$e->getMessage()}");

                    continue;
                }

                $already = $result['already'] ?? 0;
                $this->line("  playlist {$playlistId}: +{$result['seeded']} seeded, {$already} already in pool, {$result['skipped']} without a preview");
                $seeded += $result['seeded'];
                $skipped += $result['skipped'];
            }

            if ($genre === SongGenre::GermanRap) {
                $seeded += $this->seedGermanRapArtists($spotify, $seeder, $throttle);
            }
        }

        $summary = "{$seeded} tracks seeded, {$skipped} skipped for no iTunes preview";
        self::putStatus('done', $summary);

        $this->newLine();
        $this->info("Done. {$summary}. Pool now holds ".Song::count().' songs.');

        return self::SUCCESS;
    }

    /**
     * @param  'queued'|'running'|'done'|'error'  $state
     */
    public static function putStatus(string $state, ?string $summary = null): void
    {
        $existing = Cache::get(self::STATUS_CACHE_KEY, []);
        $running = in_array($state, ['queued', 'running'], true);

        Cache::forever(self::STATUS_CACHE_KEY, [
            'state' => $state,
            'summary' => $summary ?? ($running ? null : ($existing['summary'] ?? null)),
            'started_at' => $running
                ? ($state === 'queued' ? now()->toIso8601String() : ($existing['started_at'] ?? now()->toIso8601String()))
                : ($existing['started_at'] ?? null),
            'finished_at' => $running ? null : now()->toIso8601String(),
            'at' => now()->toIso8601String(),
            'pool_size' => Song::count(),
        ]);
    }

    /**
     * @return array<int, SongGenre>
     */
    private function targetGenres(): array
    {
        $configured = SeedPlaylist::configuredGenres();

        // German Rap can also seed from its artist term pool even with no
        // playlist row.
        if (! in_array(SongGenre::GermanRap, $configured, true)
            && config('music.german_rap_artists', []) !== []) {
            $configured[] = SongGenre::GermanRap;
        }

        $requested = $this->option('genre');

        if ($requested === []) {
            return $configured;
        }

        return array_values(array_filter(
            $configured,
            fn (SongGenre $g) => in_array($g->value, $requested, true),
        ));
    }

    private function seedGermanRapArtists(SpotifyClient $spotify, SongPoolSeeder $seeder, int $throttle): int
    {
        $seeded = 0;

        foreach ((array) config('music.german_rap_artists', []) as $name) {
            $id = $spotify->findArtistId($name);

            if ($id === null) {
                $this->line("  artist not found on Spotify: {$name}");

                continue;
            }

            $n = $seeder->seedArtistTopTracks($id, $throttle, SongGenre::GermanRap->value);
            $this->line("  {$name}: +{$n}");
            $seeded += $n;
        }

        return $seeded;
    }
}
