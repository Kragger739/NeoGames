<?php

namespace App\Services;

use App\Enums\DifficultyTier;
use App\Enums\SongEra;
use App\Enums\SongGenre;
use App\Models\DatasetTrack;
use App\Models\Song;
use App\Services\Music\SongPoolSeeder;
use App\Services\Music\SpotifyClient;
use App\Support\SongFilter;
use App\Support\SongSelectionContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds a random, playable song within a difficulty tier's popularity range
 * (and, optionally, a genre / release-year constraint - see
 * App\Support\SongFilter), reading entirely from the local `songs` pool.
 *
 * The pool is no longer discovered live per round. `php artisan songs:sync`
 * pre-populates it from curated Spotify playlists (metadata + the 0-100
 * `popularity` signal that DifficultyTier's bands gate on), with each
 * track's 30-second preview resolved through Apple's iTunes Search API and
 * stored on the row. iTunes preview URLs are stable and unsigned, so a
 * seeded row stays playable indefinitely - which is what lets round-time
 * selection be pure DB reads with zero third-party calls in the request
 * path. (Deezer, the previous provider, both signed its preview URLs with a
 * ~15-minute expiry AND is now Akamai-blocked from datacenter IPs.)
 *
 * Artist / MultiArtist are the exception: their pool is per-room and small,
 * so it's warmed on demand from the named artist's Spotify top tracks
 * (ensureArtistPoolReady()), eagerly via PrimeArtistSongPool / the safety
 * net in RoundService::start(), with a cached-hit fast path afterward.
 *
 * Picking within a tier is session-aware (see App\Support\
 * SongSelectionContext, built by RoundService per room game): it biases
 * toward whichever SongEra bucket is furthest behind the game's target mix
 * (config('songs.era_*_share')) via deficit scheduling, and avoids repeating
 * an artist already used this game unless every remaining candidate would
 * require one anyway (in which case only an "exceptionally popular" repeat,
 * config('songs.exceptional_artist_threshold'), is allowed). Both are soft
 * preferences applied through selection order, never hard filters - a room
 * must always get a song (see pickFallback()).
 */
class SongDiscoveryService
{
    /** How long ensureArtistPoolReady() treats an artist's fetched pool as still fresh. */
    private const ARTIST_POOL_FRESH_HOURS = 24;

    /** How many never-used, era/artist-matching candidates to randomize among, favoring higher recognizability without being fully predictable. */
    private const TOP_N_RANDOM_POOL = 5;

    /**
     * Title phrases that mark a result as something other than the original
     * artist's own recording - checked when seeding an artist's top tracks.
     *
     * @var array<int, string>
     */
    private const NON_ORIGINAL_TITLE_MARKERS = [
        'karaoke version', 'cover version', 'tribute to', 'made famous by',
        'as made famous by', 'in the style of', 'originally performed by',
    ];

    /** @var array<int, string> */
    private const NON_ORIGINAL_ARTIST_MARKERS = ['karaoke', 'tribute'];

    public function __construct(
        private SpotifyClient $spotify,
        private SongPoolSeeder $seeder,
    ) {}

    public function findRandomSongForTier(SongFilter $filter, ?SongSelectionContext $context = null): ?Song
    {
        $context ??= SongSelectionContext::empty();

        // A custom Workshop dataset is a self-contained, deliberately-chosen
        // pool: no popularity bands, no year floor, no genre filtering - just
        // pick a track the user imported.
        if ($filter->datasetId !== null) {
            return $this->pickFromDataset($filter, $context);
        }

        if ($filter->genre->isArtistSourced()) {
            return $this->findRandomSongForRelativeTier($filter, $context);
        }

        $candidate = $this->pickFromCache($filter, $context);

        if ($candidate) {
            return $candidate;
        }

        // The pool is command-seeded, not grown live - if nothing in this
        // tier's band matches, fall straight to the guaranteed-song path
        // (which relaxes popularity but never genre / release year).
        return $this->pickFallback($filter, $context->excludeTrackIds);
    }

    /**
     * Pick a track from a Workshop Songle dataset. Returns a Song row (the
     * throwaway play cache), created on demand from the dataset_tracks entry.
     * Falls back to allowing repeats once every track has been used this game.
     */
    private function pickFromDataset(SongFilter $filter, SongSelectionContext $context): ?Song
    {
        $track = DatasetTrack::where('dataset_id', $filter->datasetId)
            ->whereNotIn('provider_track_id', $context->excludeTrackIds)
            ->inRandomOrder()
            ->first()
            ?? DatasetTrack::where('dataset_id', $filter->datasetId)->inRandomOrder()->first();

        if (! $track) {
            return null;
        }

        return Song::firstOrCreate(
            ['provider_track_id' => $track->provider_track_id],
            [
                'title' => $track->title,
                'artist' => $track->artist,
                'album_art_url' => $track->album_art_url,
                'preview_url' => $track->preview_url ?? '',
                'popularity' => 50,
                'release_year' => null,
            ],
        );
    }

    /**
     * Guaranteed-song fallback: a room should virtually never come up empty
     * just because this exact tier's popularity band happens to have nothing
     * in it. Only ever relaxes popularity, never genre / release-year - those
     * are the room's actual configured intent, not a difficulty knob.
     */
    private function pickFallback(SongFilter $filter, array $exclude): ?Song
    {
        return $this->closestByPopularity($filter, $exclude);
    }

    /**
     * Same genre/release-year constraints as pickFromCache(), but ignoring
     * the tier's own popularity band - picks whichever cached song is
     * numerically closest to the tier's midpoint. Falls back to allowing
     * reuse of an already-excluded song as an explicit last resort.
     */
    private function closestByPopularity(SongFilter $filter, array $exclude): ?Song
    {
        $midpoint = $this->popularityMidpoint($filter);

        return Song::query()->matchingFilterIgnoringPopularity($filter)
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('provider_track_id', $exclude))
            ->orderByRaw('ABS(popularity - ?)', [$midpoint])
            ->first()
            ?? Song::query()->matchingFilterIgnoringPopularity($filter)
                ->orderByRaw('ABS(popularity - ?)', [$midpoint])
                ->first();
    }

    private function popularityMidpoint(SongFilter $filter): int
    {
        if ($filter->genre->isArtistSourced()) {
            return $this->relativeTierMidpoint($filter);
        }

        [$min, $max] = $filter->tier->popularityRange();

        return intdiv($min + $max, 2);
    }

    /**
     * The tier's relative position within THIS room's own pool span, as a
     * popularity value - so a niche artist's Easy target lands near the top
     * of *their* catalog, not near the meaningless global 85-100 band.
     */
    private function relativeTierMidpoint(SongFilter $filter): int
    {
        $tiers = $filter->enabledTiers !== [] ? $filter->enabledTiers : DifficultyTier::ordered();
        $bucketIndex = array_search($filter->tier, $tiers, true);
        $n = max(count($tiers), 1);

        $stats = Song::query()->matchingFilterIgnoringPopularity($filter)
            ->selectRaw('MIN(popularity) as min_pop, MAX(popularity) as max_pop')
            ->first();

        if ($stats === null || $stats->min_pop === null) {
            [$min, $max] = $filter->tier->popularityRange();

            return intdiv($min + $max, 2);
        }

        $position = $bucketIndex === false ? 0 : $bucketIndex;
        $fraction = $n > 1 ? 1 - ($position / ($n - 1)) : 1;

        return (int) round($stats->min_pop + $fraction * ($stats->max_pop - $stats->min_pop));
    }

    /**
     * Artist/MultiArtist's selection path - ranks this room's own artist-
     * filtered pool by popularity and picks from the bucket matching the
     * current tier's relative position, instead of the global absolute bands
     * (a single artist's whole catalog can sit entirely below Easy's band).
     */
    private function findRandomSongForRelativeTier(SongFilter $filter, SongSelectionContext $context): ?Song
    {
        if ($filter->genre === SongGenre::MultiArtist) {
            return $this->findRandomSongForMultiArtist($filter, $context);
        }

        $candidate = $this->pickFromBucket($this->relativeTierBucket($filter), $context->excludeTrackIds);

        if ($candidate) {
            return $candidate;
        }

        $this->ensureArtistPoolReady($filter);
        $candidate = $this->pickFromBucket($this->relativeTierBucket($filter), $context->excludeTrackIds);

        return $candidate ?? $this->pickFallback($filter, $context->excludeTrackIds);
    }

    /**
     * MultiArtist deliberately skips popularity banding: every tier draws a
     * uniform-random pick from the FULL combined pool (every named artist,
     * every song), so "which artist plays next" isn't shaped by the tier.
     */
    private function findRandomSongForMultiArtist(SongFilter $filter, SongSelectionContext $context): ?Song
    {
        $candidate = $this->pickRandomFromPool(
            Song::query()->matchingFilterIgnoringPopularity($filter)->get(),
            $context->excludeTrackIds,
        );

        if ($candidate) {
            return $candidate;
        }

        $this->ensureArtistPoolReady($filter);

        $candidate = $this->pickRandomFromPool(
            Song::query()->matchingFilterIgnoringPopularity($filter)->get(),
            $context->excludeTrackIds,
        );

        return $candidate ?? $this->pickFallback($filter, $context->excludeTrackIds);
    }

    /**
     * @param  Collection<int, Song>  $pool
     * @param  array<int, string>  $excludeTrackIds
     */
    private function pickRandomFromPool(Collection $pool, array $excludeTrackIds): ?Song
    {
        $neverUsed = $pool->whereNull('last_used_at')->whereNotIn('provider_track_id', $excludeTrackIds);

        if ($neverUsed->isNotEmpty()) {
            return $neverUsed->random();
        }

        $notExcludedThisGame = $pool->whereNotIn('provider_track_id', $excludeTrackIds);

        if ($notExcludedThisGame->isNotEmpty()) {
            return $notExcludedThisGame->sortBy('last_used_at')->first();
        }

        return null;
    }

    /**
     * Splits the full matching pool (ignoring popularity), ranked by
     * popularity descending, into N buckets - N = enabled-tier count. Bucket
     * 0 (most popular) is this room's easiest enabled tier.
     *
     * @return Collection<int, Song>
     */
    private function relativeTierBucket(SongFilter $filter): Collection
    {
        $tiers = $filter->enabledTiers !== [] ? $filter->enabledTiers : DifficultyTier::ordered();
        $bucketIndex = array_search($filter->tier, $tiers, true);
        $n = count($tiers);

        if ($bucketIndex === false) {
            return collect();
        }

        $total = Song::query()->matchingFilterIgnoringPopularity($filter)->count();

        if ($total === 0) {
            return collect();
        }

        [$offset, $size] = $this->bucketOffsetAndSize($total, $n, $bucketIndex);

        return Song::query()->matchingFilterIgnoringPopularity($filter)
            ->orderByDesc('popularity')
            ->orderBy('id')
            ->skip($offset)
            ->take($size)
            ->get();
    }

    /**
     * Splits `$total` ranked songs into `$n` roughly-equal buckets, leftover
     * to the EARLIEST (easiest) buckets first - e.g. 32/5 -> [7,7,6,6,6].
     *
     * @return array{0: int, 1: int} [$offset, $size]
     */
    private function bucketOffsetAndSize(int $total, int $n, int $bucketIndex): array
    {
        $base = intdiv($total, $n);
        $remainder = $total % $n;

        $size = $base + ($bucketIndex < $remainder ? 1 : 0);
        $offset = ($bucketIndex * $base) + min($bucketIndex, $remainder);

        return [$offset, $size];
    }

    /**
     * @param  Collection<int, Song>  $bucket
     * @param  array<int, string>  $excludeTrackIds
     */
    private function pickFromBucket(Collection $bucket, array $excludeTrackIds): ?Song
    {
        $neverUsed = $bucket->whereNull('last_used_at')->whereNotIn('provider_track_id', $excludeTrackIds);

        if ($neverUsed->isNotEmpty()) {
            return $neverUsed->sortByDesc('popularity')->take(self::TOP_N_RANDOM_POOL)->random();
        }

        $notExcludedThisGame = $bucket->whereNotIn('provider_track_id', $excludeTrackIds);

        if ($notExcludedThisGame->isNotEmpty()) {
            return $notExcludedThisGame->sortBy('last_used_at')->first();
        }

        return null;
    }

    /**
     * Was a live Deezer preview re-fetch (its URLs expired every ~15 min).
     * iTunes preview URLs seeded by `songs:sync` don't expire, so this is now
     * just a sanity check that the row actually has one - a genuinely blank
     * row is skipped and retried by RoundService::pickPlayableSong().
     */
    public function ensurePlayable(Song $song): bool
    {
        return $song->preview_url !== null && $song->preview_url !== '';
    }

    /**
     * The reveal screen's "how well known is the act" stat - the seeded
     * Spotify follower count for the track's artist, cached on the row.
     * Self-heals a row seeded without one via a single Spotify artist
     * lookup. Returns null (never a fabricated 0) if it can't be determined.
     */
    public function ensureFollowerCount(Song $song): ?int
    {
        if ($song->artist_follower_count !== null) {
            return $song->artist_follower_count;
        }

        if ($song->artist_provider_id === null) {
            return null;
        }

        $followers = $this->spotify->artistFollowerCount($song->artist_provider_id);

        if ($followers !== null) {
            $song->update(['artist_follower_count' => $followers]);
        }

        return $followers;
    }

    /**
     * Kept for the ExpandSongPool job's signature. For a fixed-playlist genre
     * the pool is owned by `songs:sync`, so this only reports the current
     * count; for Artist / MultiArtist it still warms the per-room pool.
     */
    public function topUpTier(SongFilter $filter): int
    {
        if ($filter->genre->isArtistSourced()) {
            $this->ensureArtistPoolReady($filter);

            return Song::query()->matchingFilterIgnoringPopularity($filter)->count();
        }

        return Song::query()->matchingFilter($filter)->count();
    }

    /**
     * Prefers never-used songs over already-played ones (outermost priority,
     * checked across every relaxation level first), then falls back to the
     * least-recently-used match - keeps the pool rotating fairly instead of
     * replaying the same top scorers.
     */
    private function pickFromCache(SongFilter $filter, SongSelectionContext $context): ?Song
    {
        return $this->pickNeverUsed($filter, $context) ?? $this->pickLeastRecentlyUsed($filter, $context);
    }

    private function pickNeverUsed(SongFilter $filter, SongSelectionContext $context): ?Song
    {
        foreach ($this->relaxationLevels($context) as [$excludeArtistIds, $era, $minPopularity]) {
            $candidates = $this->candidateQuery($filter, $context->excludeTrackIds, $excludeArtistIds, $era, $minPopularity)
                ->whereNull('last_used_at')
                ->orderByDesc('popularity')
                ->limit(self::TOP_N_RANDOM_POOL)
                ->get();

            if ($candidates->isNotEmpty()) {
                return $candidates->random();
            }
        }

        return null;
    }

    private function pickLeastRecentlyUsed(SongFilter $filter, SongSelectionContext $context): ?Song
    {
        foreach ($this->relaxationLevels($context) as [$excludeArtistIds, $era, $minPopularity]) {
            $song = $this->candidateQuery($filter, $context->excludeTrackIds, $excludeArtistIds, $era, $minPopularity)
                ->orderBy('last_used_at')
                ->first();

            if ($song) {
                return $song;
            }
        }

        return null;
    }

    /**
     * Progressively looser era/artist relaxation levels, each a superset of
     * the last:
     *  1. neediest era (deficit scheduling), no artist repeat
     *  2. any era, no artist repeat
     *  3. any era, artist repeats allowed but only for "exceptionally
     *     popular" candidates
     *  4. any era, any artist - variety fully given up before ever leaving
     *     the room without a song
     *
     * @return array<int, array{0: array<int, string>, 1: ?SongEra, 2: ?int}>
     */
    private function relaxationLevels(SongSelectionContext $context): array
    {
        $exceptionalThreshold = (int) config('songs.exceptional_artist_threshold');

        return [
            [$context->usedArtistProviderIds, $context->neediestEra(), null],
            [$context->usedArtistProviderIds, null, null],
            [[], null, $exceptionalThreshold],
            [[], null, null],
        ];
    }

    /**
     * @param  array<int, string>  $excludeTrackIds
     * @param  array<int, string>  $excludeArtistIds
     */
    private function candidateQuery(
        SongFilter $filter,
        array $excludeTrackIds,
        array $excludeArtistIds,
        ?SongEra $era,
        ?int $minPopularity,
    ): Builder {
        $query = Song::query()->matchingFilter($filter);

        if ($excludeTrackIds !== []) {
            $query->whereNotIn('provider_track_id', $excludeTrackIds);
        }

        if ($excludeArtistIds !== []) {
            // A NULL artist_provider_id can't be known to repeat anything -
            // keep it eligible (SQL NOT IN against NULL never matches).
            $query->where(function (Builder $q) use ($excludeArtistIds) {
                $q->whereNotIn('artist_provider_id', $excludeArtistIds)
                    ->orWhereNull('artist_provider_id');
            });
        }

        if ($era !== null) {
            $query->inEraBucket($era);
        }

        if ($minPopularity !== null) {
            $query->where('popularity', '>=', $minPopularity);
        }

        return $query;
    }

    /**
     * Populates the local pool with each named artist's Spotify top tracks
     * (preview resolved via iTunes), one artist at a time. Artist/MultiArtist
     * rank relative to this pool at query time (see relativeTierBucket()), so
     * nothing is discarded here for being "too popular" or "too obscure" -
     * only the cover/karaoke and year-floor checks apply. Guarded by a 24h
     * per-artist freshness key so the repeated callers (the RoundService
     * safety net, PrimeArtistSongPool) are near-free once warm.
     */
    public function ensureArtistPoolReady(SongFilter $filter): void
    {
        foreach ($this->artistNamesFor($filter) as $artistName) {
            try {
                $this->warmArtistPool($artistName);
            } catch (Throwable $e) {
                // Best-effort: a Spotify / iTunes hiccup here must not break
                // room creation or a round start. The relative-tier fallback
                // chain still serves whatever is already pooled, and the
                // next call retries once the freshness key has lapsed.
                Log::warning('Artist pool warm failed', [
                    'artist' => $artistName,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function artistNamesFor(SongFilter $filter): array
    {
        return match ($filter->genre) {
            SongGenre::MultiArtist => $filter->artistNames ?? [],
            SongGenre::Artist => array_filter([$filter->artistName]),
            default => [],
        };
    }

    private function warmArtistPool(string $artistName): void
    {
        $artistId = $this->resolveArtistId($artistName);

        if ($artistId === null) {
            return;
        }

        $freshKey = "music:artist-pool-fresh:{$artistId}";

        if (Cache::has($freshKey)) {
            return;
        }

        $this->seeder->seedArtistTopTracks($artistId);

        Cache::put($freshKey, true, now()->addHours(self::ARTIST_POOL_FRESH_HOURS));
    }

    private function resolveArtistId(?string $artistName): ?string
    {
        if ($artistName === null || trim($artistName) === '') {
            return null;
        }

        $key = 'music:artist-id:'.mb_strtolower(trim($artistName));

        return Cache::remember($key, now()->addDays(30), fn () => $this->spotify->findArtistId($artistName));
    }

    /**
     * Whether a title / artist string pair reads as a karaoke track, a
     * tribute-band cover, etc. rather than the original recording - applied
     * when seeding an artist's top tracks.
     */
    public function looksLikeNonOriginalRecording(string $title, string $artist): bool
    {
        $title = strtolower($title);
        $artist = strtolower($artist);

        foreach (self::NON_ORIGINAL_TITLE_MARKERS as $marker) {
            if (str_contains($title, $marker)) {
                return true;
            }
        }

        foreach (self::NON_ORIGINAL_ARTIST_MARKERS as $marker) {
            if (str_contains($artist, $marker)) {
                return true;
            }
        }

        return false;
    }
}
