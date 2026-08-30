<?php

namespace App\Console\Commands;

use App\Enums\SongGenre;
use App\Models\Song;
use App\Services\Music\SongPoolSeeder;
use App\Services\Music\SpotifyClient;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Rebuilds the local `songs` pool from the curated Spotify playlists in
 * config/music.php, resolving each track's 30-second preview through Apple's
 * iTunes Search API. This is the only thing that touches a music API - game
 * rounds read the pool directly. Run it manually or on a schedule (see
 * routes/console.php).
 *
 *   php artisan songs:sync                 # every configured genre
 *   php artisan songs:sync --genre=iconic --genre=pop
 *   php artisan songs:sync --fresh         # wipe the pool first
 */
class SyncSongsCommand extends Command
{
    protected $signature = 'songs:sync {--genre=* : Genre value(s) to sync; defaults to all configured} {--fresh : Delete the whole songs pool before seeding}';

    protected $description = 'Seed the Songle song pool from Spotify playlists (+ iTunes previews)';

    public function handle(SpotifyClient $spotify, SongPoolSeeder $seeder): int
    {
        if (! config('services.spotify.client_id') || ! config('services.spotify.client_secret')) {
            $this->error('SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET are not set.');

            return self::FAILURE;
        }

        $genres = $this->targetGenres();

        if ($genres === []) {
            $this->warn('No genres to sync - configure MUSIC_PLAYLISTS_* in the environment.');

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $count = Song::query()->delete();
            $this->line("Wiped {$count} existing rows.");
        }

        $throttle = (int) config('music.itunes_throttle_ms', 3200);
        $totalSeeded = 0;
        $totalSkipped = 0;

        foreach ($genres as $genre) {
            $this->info("── {$genre->value} ──");

            foreach ($genre->spotifyPlaylistIds() as $playlistRef) {
                try {
                    $result = $seeder->seedPlaylist(
                        $playlistRef,
                        $genre->cacheTag(),
                        $throttle,
                        fn (string $line) => $this->line($line),
                    );
                } catch (RuntimeException $e) {
                    $this->error("  playlist {$playlistRef}: {$e->getMessage()}");

                    continue;
                }

                $this->line("  playlist {$playlistRef}: +{$result['seeded']} seeded, {$result['skipped']} without a preview");
                $totalSeeded += $result['seeded'];
                $totalSkipped += $result['skipped'];
            }

            if ($genre === SongGenre::GermanRap) {
                $totalSeeded += $this->seedGermanRapArtists($spotify, $seeder, $throttle);
            }
        }

        $this->newLine();
        $this->info("Done. {$totalSeeded} tracks in the pool from this run, {$totalSkipped} skipped for no iTunes preview.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, SongGenre>
     */
    private function targetGenres(): array
    {
        $requested = $this->option('genre');

        $all = array_filter(
            SongGenre::cases(),
            fn (SongGenre $g) => ! $g->isArtistSourced()
                && ($g->spotifyPlaylistIds() !== [] || $g === SongGenre::GermanRap),
        );

        if ($requested === []) {
            return array_values($all);
        }

        return array_values(array_filter(
            $all,
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
