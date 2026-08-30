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
        if (! config('services.spotify.client_id') || ! config('services.spotify.client_secret')) {
            $this->error('SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET are not set.');

            return self::FAILURE;
        }

        $genres = $this->targetGenres();

        if ($genres === []) {
            $this->warn('No genres to sync - add Spotify playlists in the admin dashboard first.');

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

                $this->line("  playlist {$playlistId}: +{$result['seeded']} seeded, {$result['skipped']} without a preview");
                $seeded += $result['seeded'];
                $skipped += $result['skipped'];
            }

            if ($genre === SongGenre::GermanRap) {
                $seeded += $this->seedGermanRapArtists($spotify, $seeder, $throttle);
            }
        }

        $summary = "{$seeded} tracks seeded, {$skipped} skipped for no iTunes preview";
        Cache::forever(self::STATUS_CACHE_KEY, [
            'at' => now()->toIso8601String(),
            'summary' => $summary,
            'pool_size' => Song::count(),
        ]);

        $this->newLine();
        $this->info("Done. {$summary}. Pool now holds ".Song::count().' songs.');

        return self::SUCCESS;
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
