<?php

namespace App\Services;

use App\Enums\DifficultyTier;
use App\Enums\SongEra;
use App\Enums\SongGenre;
use App\Models\Song;
use App\Services\Deezer\DeezerClient;
use App\Support\SongFilter;
use App\Support\SongSelectionContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Finds a random, playable song within a difficulty tier's popularity
 * range (and, optionally, a genre/release-year constraint - see
 * App\Support\SongFilter), backed by a local cache (the `songs` table) so
 * repeat games don't re-hit Deezer for tracks already discovered.
 *
 * Sourced entirely from Deezer (see App\Services\Deezer\DeezerClient) -
 * previously iTunes + Last.fm, and briefly considered Spotify, but
 * Spotify's Web API strips both `preview_url` and `popularity` from every
 * track response for any app created after Nov 2024 (confirmed by direct
 * testing), making it structurally unusable for this game. Deezer supplies
 * a real, playable preview_url and an inline popularity signal (`rank`) on
 * every search/chart result with no auth required at all.
 *
 * Discovery itself is split by tier, not just filtered after the fact:
 * Easy/Intermediate/Medium source candidates from a real Deezer chart
 * (discoverFromChart()) - the global/genre chart - so every candidate is
 * something that's actually, legitimately charting. Hard/Extreme need
 * popularity too low for any chart to ever list, so they fall back to a
 * generic word search instead (discoverFromWordSearch()). Both paths run
 * every candidate through looksLikeNonOriginalRecording() first, since
 * neither source is immune to karaoke tracks, tribute-band covers, etc.
 * slipping in under a chart's exact-name match or a generic search term.
 *
 * Genre filtering (Pop/Hip-hop) is a discovery-time chart facet, not a
 * post-hoc content filter: Deezer has no per-track genre field at all
 * (confirmed by direct testing), so "Pop" only ever means "discovered via
 * Deezer's Pop chart". cache() writes this as a monotonic tag - once a
 * cached row is tagged with a genre it never gets un-tagged by a later,
 * untagged discovery pass - so a song can migrate from untagged to
 * genre-tagged over time without losing its other cached data.
 *
 * Genre on the word-search path (Hard/Extreme) is best-effort, via a
 * search-query bias term (see randomSearchQuery()) rather than a hard
 * constraint - Deezer's /search has no genre facet. German Rap is the one
 * exception to the chart-sourced-when-possible rule above: Deezer's global
 * Rap/Hip Hop chart is dominated by English-language content, not German,
 * and there's no language-specific chart to use instead (confirmed live),
 * so it always uses word search, even for Easy/Intermediate/Medium -
 * biased toward a curated pool of known German rap artist names
 * (GERMAN_RAP_SEARCH_TERMS) rather than a generic bias word, since
 * searching for the artists directly is far more reliable than hoping a
 * plain English word plus "deutschrap" surfaces the right thing.
 *
 * songs.popularity is a recognizability score, not raw Deezer rank: it
 * blends rank (how big is this right now) with the artist's overall fan
 * count (how famous is the act behind it, log-scaled - see
 * recognizabilityScore()/fanPopularity()) so a legendary act's deep cut
 * outranks an unknown act's momentary viral blip, not just whatever's
 * charting this exact week. DifficultyTier's bands still gate on this same
 * 0-100 number, unchanged - only what it means changed.
 *
 * Picking within a tier is session-aware (see App\Support\
 * SongSelectionContext, built by RoundService per room game): it biases
 * toward whichever SongEra bucket is furthest behind the game's target mix
 * (config('songs.era_*_share')) via deficit scheduling, and avoids
 * repeating an artist already used this game unless every remaining
 * candidate would require one anyway (in which case only an
 * "exceptionally popular" repeat, config('songs.exceptional_artist_
 * threshold'), is allowed). Both are soft preferences applied through
 * selection order, never hard filters - a room must always get a song
 * (see pickFallback()), even if that means giving up on era/artist variety
 * entirely as a last resort.
 *
 * Artist is the other exception to the chart/word-search split: it always
 * sources from that one act's own top tracks (discoverFromArtist()), via
 * GET /artist/{id}/top, never a chart or generic search - the host's typed
 * name is resolved to a Deezer artist id first (resolveArtistId()), which
 * needs its own care: Deezer's /search/artist relevance does NOT rank by
 * fame (confirmed live - searching "Drake" surfaces two decoy artists with
 * 76 and 95 fans, literally also named Drake, ahead of the real one, a
 * 24-million-fan artist). Resolution instead filters results to an exact
 * case-insensitive name match first, then picks the highest fan count among
 * those - exact-match-first matters because a same-fame-tier but
 * differently-named artist (e.g. "Dido") can otherwise outrank the
 * intended one (e.g. "Sido") on fan count alone. Every song discovered this
 * way is matched back against the room's songs.artist column directly, not
 * a genre tag (see SongGenre::cacheTag(), Song::scopeMatchingFilterIgnoringPopularity()).
 */
class SongDiscoveryService
{
    private const SEARCH_TERMS = [
        'love', 'you', 'the', 'me', 'life', 'time', 'night', 'heart', 'baby',
        'world', 'light', 'dream', 'home', 'fire', 'rain', 'sun', 'stars',
        'again', 'way', 'gone', 'never', 'always', 'good', 'bad', 'young',
    ];

    private const DECADE_STARTS = [2000, 2010, 2020];

    /** Word-search decade bias for Classics, which has no fixed year range of its own. */
    private const CLASSICS_DECADE_STARTS = [1950, 1960, 1970, 1980, 1990];

    /** Best-effort word-search genre bias - see class docblock. */
    private const GENRE_BIAS_TERMS = [
        SongGenre::Pop->value => ['pop'],
        SongGenre::HipHop->value => ['hip hop', 'rap'],
    ];

    /**
     * German Rap's dedicated search-term pool (well-known artists, searched
     * directly rather than biased with an appended word) - see class
     * docblock for why this genre always uses word search.
     */
    private const GERMAN_RAP_SEARCH_TERMS = [
        'capital bra', 'bonez mc', 'raf camora', 'apache 207', 'sido',
        'kollegah', 'kc rebell', 'summer cem', 'shirin david', 'ufo361',
        '187 strassenbande', 'luciano', 'kalim', 'ceezy', 'nimo',
    ];

    private const SEARCH_LIMIT = 10;

    private const CHART_LIMIT = 50;

    private const MAX_DISCOVERY_ATTEMPTS = 4;

    /** How many never-used, era/artist-matching candidates to randomize among, favoring higher recognizability without being fully predictable. */
    private const TOP_N_RANDOM_POOL = 5;

    /**
     * Tiers whose popularity band a real chart can plausibly reach. Hard/
     * Extreme are deliberately excluded - see class docblock.
     *
     * @var array<int, DifficultyTier>
     */
    private const CHART_SOURCED_TIERS = [
        DifficultyTier::Easy,
        DifficultyTier::Intermediate,
        DifficultyTier::Medium,
    ];

    /**
     * Title phrases that mark a result as something other than the
     * original artist's own recording. Deliberately multi-word/specific -
     * a bare "tribute" or "karaoke" here would also reject genuine titles
     * (e.g. Tenacious D's "Tribute"), so those single words are only
     * checked against the artist name below.
     *
     * @var array<int, string>
     */
    private const NON_ORIGINAL_TITLE_MARKERS = [
        'karaoke version', 'cover version', 'tribute to', 'made famous by',
        'as made famous by', 'in the style of', 'originally performed by',
    ];

    /**
     * Artist-name substrings that mark a generic session/karaoke/tribute
     * act rather than the actual recording artist - real artists don't
     * put these words in their own name.
     *
     * @var array<int, string>
     */
    private const NON_ORIGINAL_ARTIST_MARKERS = ['karaoke', 'tribute'];

    public function __construct(
        private DeezerClient $deezer,
    ) {}

    public function findRandomSongForTier(SongFilter $filter, ?SongSelectionContext $context = null): ?Song
    {
        $context ??= SongSelectionContext::empty();
        $targetEra = $context->neediestEra();

        $candidate = $this->pickFromCache($filter, $context);

        if ($candidate) {
            return $candidate;
        }

        for ($attempt = 0; $attempt < self::MAX_DISCOVERY_ATTEMPTS; $attempt++) {
            $this->discoverAndCache($filter, $targetEra);

            $candidate = $this->pickFromCache($filter, $context);

            if ($candidate) {
                return $candidate;
            }
        }

        return $this->pickFallback($filter, $context->excludeTrackIds);
    }

    /**
     * Guaranteed-song fallback, only reached once real discovery has
     * already exhausted every attempt: a room should virtually never come
     * up completely empty just because this exact tier's popularity band
     * happens to have nothing in it right now. Only ever relaxes
     * popularity, never genre/release-year - those are the room's actual
     * configured intent, not a difficulty knob, so a Pop or Classics room
     * can never be served a song that doesn't actually match.
     */
    private function pickFallback(SongFilter $filter, array $exclude): ?Song
    {
        return $this->closestByPopularity($filter, $exclude);
    }

    /**
     * Same genre/release-year constraints as pickFromCache(), but ignoring
     * the tier's own popularity band - picks whichever cached song is
     * numerically closest to the tier's midpoint instead.
     */
    private function closestByPopularity(SongFilter $filter, array $exclude): ?Song
    {
        [$min, $max] = $filter->tier->popularityRange();
        $midpoint = intdiv($min + $max, 2);

        return Song::query()->matchingFilterIgnoringPopularity($filter)
            ->when($exclude !== [], fn ($query) => $query->whereNotIn('deezer_track_id', $exclude))
            ->orderByRaw('ABS(popularity - ?)', [$midpoint])
            ->first();
    }

    /**
     * Deezer's preview URL is a short-lived signed link (confirmed by
     * direct testing - it expires roughly 15 minutes after being issued),
     * unlike iTunes's old stable, indefinitely-cacheable previewUrl. A
     * cached Song's preview_url can easily be stale by the time it's
     * actually picked for a round, so this re-fetches a fresh one right
     * before the round that's about to use it starts, and persists it back
     * so later reads of the same row (stage advances, reconnects) within
     * that round stay correct too. Returns false if the track no longer has
     * any preview available at all (rare, but possible - removed from
     * Deezer, newly region-blocked, etc.), so the caller can skip it.
     */
    public function ensurePlayable(Song $song): bool
    {
        $details = $this->deezer->trackDetails($song->deezer_track_id);

        if (! $details || ! $details['preview_url']) {
            return false;
        }

        $song->update(['preview_url' => $details['preview_url']]);

        return true;
    }

    /**
     * The reveal screen's "how popular is this" stat - Deezer has no
     * per-track play/fan count at all (confirmed by direct testing), only
     * this per-artist figure, so it's cached once per song rather than
     * fetched on every reveal (unlike ensurePlayable(), fan counts don't
     * expire, they're just eventually a little stale, which is fine).
     * Self-healing for songs cached before this field existed: falls back
     * to one trackDetails() call to recover the artist id if it's missing.
     * Returns null - never a fabricated 0 - if the count genuinely can't be
     * determined (artist lookup fails, track's gone, etc.).
     */
    public function ensureFanCount(Song $song): ?int
    {
        if ($song->artist_fan_count !== null) {
            return $song->artist_fan_count;
        }

        $artistDeezerId = $song->artist_deezer_id;

        if ($artistDeezerId === null) {
            $details = $this->deezer->trackDetails($song->deezer_track_id);
            $artistDeezerId = $details['artist_deezer_id'] ?? null;

            if ($artistDeezerId === null) {
                return null;
            }

            $song->update(['artist_deezer_id' => $artistDeezerId]);
        }

        $fanCount = $this->deezer->artistFanCount($artistDeezerId);

        if ($fanCount !== null) {
            $song->update(['artist_fan_count' => $fanCount]);
        }

        return $fanCount;
    }

    /**
     * Runs live discovery passes to grow the local cache for a filter,
     * independent of any single room's pick. Used by ExpandSongPool to top
     * up the pool in the background so rounds keep drawing from a larger,
     * fresher set over time without any single round blocking on a live
     * search itself.
     *
     * @return int how many songs matching this filter are now cached
     */
    public function topUpTier(SongFilter $filter, int $attempts = self::MAX_DISCOVERY_ATTEMPTS): int
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $this->discoverAndCache($filter);
        }

        return Song::query()->matchingFilter($filter)->count();
    }

    /**
     * Prefers songs never used in any room over ones already played, so a
     * fresh lobby doesn't just redraw from the same handful of tracks a
     * previous game already picked - this split is the outermost priority,
     * checked in full (across every era/artist relaxation level) before
     * ever considering an already-used song, so era/artist targeting can
     * never cause an already-used song to jump ahead of a never-used one.
     * Once everything in range has been played at least once, falls back to
     * the single least-recently-used match within whichever relaxation
     * level finds anything - deliberately not score-weighted there, since
     * once the pool matures that's what keeps the whole thing rotating
     * fairly instead of the same handful of top scorers getting replayed
     * indefinitely while lower (but still real) scorers never surface.
     */
    private function pickFromCache(SongFilter $filter, SongSelectionContext $context): ?Song
    {
        return $this->pickNeverUsed($filter, $context) ?? $this->pickLeastRecentlyUsed($filter, $context);
    }

    /**
     * Among never-used matches, picks randomly among the top
     * TOP_N_RANDOM_POOL by recognizability score - favors higher scores
     * without being fully predictable. Tries each era/artist relaxation
     * level (see relaxationLevels()) in order, stopping at the first that
     * finds anything.
     */
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
     * Progressively looser era/artist relaxation levels, each a strict
     * superset of the last - era/artist preferences never block a pick,
     * they only shape which of several otherwise-equally-valid matches gets
     * chosen, stopping at the first level that finds anything:
     *
     * 1. Target the game's neediest era (deficit scheduling, see
     *    SongSelectionContext::neediestEra()), no artist repeat.
     * 2. Any era, still no artist repeat.
     * 3. Any era, artist repeats allowed but only among "exceptionally
     *    popular" candidates (config('songs.exceptional_artist_threshold'))
     *    - by this point every non-repeat option is already known to be
     *    exhausted.
     * 4. Any era, any artist - variety fully given up rather than ever
     *    leaving the room without a song.
     *
     * @return array<int, array{0: array<int, string>, 1: ?SongEra, 2: ?int}>
     */
    private function relaxationLevels(SongSelectionContext $context): array
    {
        $exceptionalThreshold = (int) config('songs.exceptional_artist_threshold');

        return [
            [$context->usedArtistDeezerIds, $context->neediestEra(), null],
            [$context->usedArtistDeezerIds, null, null],
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
            $query->whereNotIn('deezer_track_id', $excludeTrackIds);
        }

        if ($excludeArtistIds !== []) {
            // A NULL artist_deezer_id (a song cached before artist ids were
            // captured) can't be known to repeat anything - it stays
            // eligible rather than being silently dropped by a plain
            // whereNotIn (SQL's NOT IN against NULL never matches).
            $query->where(function (Builder $q) use ($excludeArtistIds) {
                $q->whereNotIn('artist_deezer_id', $excludeArtistIds)
                    ->orWhereNull('artist_deezer_id');
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

    private function discoverAndCache(SongFilter $filter, ?SongEra $targetEra = null): void
    {
        [$min, $max] = $filter->tier->popularityRange();

        // Artist always sources from that one act's own top tracks,
        // regardless of tier or era target - checked first so it can never
        // fall through to a chart or generic search, exactly like German
        // Rap's override below.
        if ($filter->genre === SongGenre::Artist) {
            $this->discoverFromArtist($filter, $min, $max);

            return;
        }

        // Deezer's chart endpoint only ever reflects current
        // popularity - there is no "chart as of N years ago" to source
        // older material from at all, so hunting a Classic or Mainstream
        // candidate always uses word-search with an old-decade bias
        // instead, even for tiers that would normally be chart-sourced.
        // Without this, chart-sourced tiers can structurally never surface
        // anything but brand-new releases (confirmed live - a full
        // simulated game skewed 14/15 picks Current with the rest
        // Mainstream and zero Classic, because every chart-sourced
        // discovery pass only ever returns what's charting *today*).
        // Current itself needs no diversion - that's exactly what chart()
        // already, naturally supplies.
        if ($targetEra === SongEra::Classic || $targetEra === SongEra::Mainstream) {
            $this->discoverFromWordSearch($filter, $min, $max, seekingEra: $targetEra);

            return;
        }

        // German Rap always uses word search, even for chart-sourced tiers
        // - see class docblock.
        if ($filter->genre === SongGenre::GermanRap) {
            $this->discoverFromWordSearch($filter, $min, $max);

            return;
        }

        if (in_array($filter->tier, self::CHART_SOURCED_TIERS, true)) {
            $this->discoverFromChart($filter, $min, $max);

            return;
        }

        $this->discoverFromWordSearch($filter, $min, $max);
    }

    /**
     * Every candidate here is something a real Deezer chart says is
     * genuinely charting, with a first-pass popularity read straight off
     * the chart entry (cheap - avoids wasting an artistFanCount() call on
     * something wildly outside this tier's band already). Still has to hit
     * trackDetails() once per surviving candidate, since chart/search
     * results carry no release date (see class docblock) - and, now,
     * artistFanCount() once more to compute the blended recognizability
     * score that's actually cached (see class docblock), re-checked against
     * the band since folding in fan count can shift a borderline candidate.
     */
    private function discoverFromChart(SongFilter $filter, int $min, int $max): void
    {
        $candidates = $this->deezer->chart($filter->genre->deezerGenreId(), self::CHART_LIMIT);

        $this->processCandidates($filter, $candidates, $min, $max);
    }

    /**
     * Fallback for Hard/Extreme, where no legitimate chart ever lists
     * anything this obscure - candidates come from a generic Deezer search
     * instead, with the same non-original-recording filter applied.
     * Popularity comes free on every search result (no extra HTTP call),
     * unlike the old iTunes-then-Last.fm design this replaces.
     */
    private function discoverFromWordSearch(SongFilter $filter, int $min, int $max, ?SongEra $seekingEra = null): void
    {
        $results = $this->deezer->search($this->randomSearchQuery($filter, $seekingEra), self::SEARCH_LIMIT);

        $this->processCandidates($filter, $results, $min, $max);
    }

    /**
     * Artist genre's dedicated discovery path - see class docblock. Resolves
     * the host's typed name to a Deezer artist id (cached 30 days; a cached
     * null is treated as a cache miss by Cache::remember and simply retries
     * the lookup next call, not a bug), then pulls that artist's own top
     * tracks and runs them through the same per-candidate pipeline as the
     * chart/word-search paths. A name that resolves to nothing (Deezer has
     * literally no matching artist) leaves the cache untouched for this
     * pass - findRandomSongForTier()'s retry loop and eventual fallback
     * still apply exactly as for any other genre that comes up empty.
     */
    private function discoverFromArtist(SongFilter $filter, int $min, int $max): void
    {
        $artistId = $this->resolveArtistId($filter->artistName);

        if ($artistId === null) {
            return;
        }

        $candidates = $this->deezer->artistTopTracks($artistId, self::CHART_LIMIT);

        $this->processCandidates($filter, $candidates, $min, $max);
    }

    private function resolveArtistId(?string $artistName): ?string
    {
        if ($artistName === null || trim($artistName) === '') {
            return null;
        }

        $key = 'deezer:artist-id:'.mb_strtolower(trim($artistName));

        return Cache::remember($key, now()->addDays(30), fn () => $this->deezer->findArtistId($artistName));
    }

    /**
     * Shared by discoverFromChart()/discoverFromWordSearch()/
     * discoverFromArtist(): preview check -> popularity pre-check (cheap,
     * avoids wasting an artistFanCount() call on something wildly outside
     * this tier's band already) -> non-original-recording check ->
     * trackDetails() (release date isn't on chart/search/artist-top results
     * - see class docblock) -> fan count -> composite recognizability score
     * -> band re-check (folding in fan count can shift a borderline
     * candidate) -> cache().
     *
     * @param  array<int, array{deezer_track_id: string, title: string, artist: string, artist_deezer_id: ?string, album_art_url: ?string, preview_url: ?string, popularity: int}>  $candidates
     */
    private function processCandidates(SongFilter $filter, array $candidates, int $min, int $max): void
    {
        foreach ($candidates as $candidate) {
            if (! $candidate['preview_url']) {
                continue;
            }

            if ($candidate['popularity'] < $min || $candidate['popularity'] > $max) {
                continue;
            }

            if ($this->looksLikeNonOriginalRecording($candidate['title'], $candidate['artist'])) {
                continue;
            }

            $details = $this->deezer->trackDetails($candidate['deezer_track_id']);

            if (! $details || ! $this->passesYearFloor($filter, $details['release_year'])) {
                continue;
            }

            $fanCount = $this->fanCountFor($details['artist_deezer_id']);
            $score = $this->recognizabilityScore($candidate['popularity'], $fanCount);

            if ($score < $min || $score > $max) {
                continue;
            }

            $this->cache($filter, $candidate, $score, $details['release_year'], $details['artist_deezer_id'], $fanCount);
        }
    }

    private function fanCountFor(?string $artistDeezerId): ?int
    {
        return $artistDeezerId === null ? null : $this->deezer->artistFanCount($artistDeezerId);
    }

    /**
     * Blends Deezer's live rank (how big is this right now, already 0-100)
     * with the artist's overall fame (log-scaled fan count, since fan
     * counts span orders of magnitude - see class docblock) into the single
     * 0-100 number DifficultyTier's bands gate on. Rank is weighted higher
     * (config-tunable, default 0.7/0.3): it's the most direct "is this
     * actually charting" signal, fan count is a secondary boost so a
     * legendary act's song doesn't get buried by an unknown act's momentary
     * viral blip. A null fan count means "couldn't be determined" (no
     * artist id, a failed lookup) - not "zero fans" - so it falls back to
     * rank alone rather than always dragging the score down by the fan
     * weight's share whenever that data just happens to be unavailable.
     */
    private function recognizabilityScore(int $rankPopularity, ?int $fanCount): int
    {
        if ($fanCount === null) {
            return $rankPopularity;
        }

        $rankWeight = (float) config('songs.recognizability_rank_weight');
        $fanWeight = (float) config('songs.recognizability_fan_weight');

        $score = $rankWeight * $rankPopularity + $fanWeight * $this->fanPopularity($fanCount);

        return (int) round(min(max($score, 0), 100));
    }

    /**
     * Log-scaled 0-100: fan counts range from ~0 for a total unknown up
     * into the tens of millions for global superstars (confirmed live -
     * Drake/Eminem/Adele/The Weeknd 15-24M, Daft Punk ~5M, a real-but-niche
     * artist ~10K), so a linear scale would make everything short of a
     * superstar score near-zero, the same problem MAX_RANK's linear scale
     * has for chart rank at the low end.
     */
    private function fanPopularity(?int $fanCount): int
    {
        if ($fanCount === null || $fanCount < 1) {
            return 0;
        }

        $floor = (float) config('songs.fan_score_floor');
        $ceiling = (float) config('songs.fan_score_ceiling');

        $ratio = (log10($fanCount) - log10($floor)) / (log10($ceiling) - log10($floor));

        return (int) round(min(max($ratio, 0), 1) * 100);
    }

    /**
     * @param  array{deezer_track_id: string, title: string, artist: string, album_art_url: ?string, preview_url: ?string}  $track
     */
    private function cache(SongFilter $filter, array $track, int $popularity, ?int $releaseYear, ?string $artistDeezerId, ?int $fanCount): void
    {
        $tag = $filter->genre->cacheTag();

        $attributes = [
            'title' => $track['title'],
            'artist' => $track['artist'],
            // Captured from the trackDetails()/artistFanCount() calls
            // already made above (for the recognizability score) - both
            // now eager at discovery time instead of lazy-at-reveal-only.
            // ensureFanCount() still self-heals rows cached before this.
            'artist_deezer_id' => $artistDeezerId,
            'artist_fan_count' => $fanCount,
            'preview_url' => $track['preview_url'],
            'album_art_url' => $track['album_art_url'],
            'popularity' => $popularity,
            'release_year' => $releaseYear,
        ];

        // Monotonic tagging (see class docblock): only ever set a genre tag,
        // never clear an existing one back to null on a later untagged pass.
        if ($tag !== null) {
            $attributes['genre'] = $tag;
        }

        Song::updateOrCreate(
            ['deezer_track_id' => $track['deezer_track_id']],
            $attributes,
        );
    }

    private function looksLikeNonOriginalRecording(string $title, string $artist): bool
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

    /**
     * The floor below which a candidate is discarded before ever being
     * cached. Normal/Pop/Hip-hop share today's baseline; Classics relaxes
     * it so pre-2000 hits can surface at all; Year uses the room's own
     * lower bound (an upper bound is enforced separately in
     * passesYearFloor(), since this method mirrors Song::scopeMatchingFilter()'s
     * lower-bound half only).
     */
    private function discoveryYearFloor(SongFilter $filter): int
    {
        return match ($filter->genre) {
            // Same relaxed floor as Classics - a host naming a pre-2000 act
            // (Elvis, The Beatles) must not have their whole catalog
            // filtered out by a floor that makes no sense for this mode.
            SongGenre::Classics, SongGenre::Artist => (int) config('songs.classics_min_release_year'),
            SongGenre::Year => $filter->yearFrom ?? (int) config('songs.min_release_year'),
            default => (int) config('songs.min_release_year'),
        };
    }

    private function passesYearFloor(SongFilter $filter, ?int $releaseYear): bool
    {
        if ($releaseYear === null || $releaseYear < $this->discoveryYearFloor($filter)) {
            return false;
        }

        if ($filter->genre === SongGenre::Year && $filter->yearTo !== null && $releaseYear > $filter->yearTo) {
            return false;
        }

        return true;
    }

    /**
     * Which decades discoverFromWordSearch() should bias its search terms
     * toward. Without this, Hard/Extreme's word search would only ever
     * pull from today's fixed DECADE_STARTS (2000s/2010s/2020s), making
     * Classics/Year nearly non-functional on this path.
     *
     * @return array<int, int>
     */
    private function decadeCandidates(SongFilter $filter): array
    {
        return match ($filter->genre) {
            SongGenre::Classics => self::CLASSICS_DECADE_STARTS,
            SongGenre::Year => $this->decadesInRange($filter->yearFrom, $filter->yearTo),
            default => self::DECADE_STARTS,
        };
    }

    /**
     * @return array<int, int>
     */
    private function decadesInRange(?int $from, ?int $to): array
    {
        if ($from === null || $to === null || $from > $to) {
            return self::DECADE_STARTS;
        }

        $decades = range(intdiv($from, 10) * 10, intdiv($to, 10) * 10, 10);

        return $decades !== [] ? $decades : self::DECADE_STARTS;
    }

    /**
     * $seekingEra biases the decade toward something old, always still
     * within the room's own genre constraints (decadeCandidates($filter) -
     * never a fixed list that could violate the genre's own year floor,
     * e.g. searching the 1970s for a Normal-genre room, whose floor is
     * 2000): Classic deterministically picks the OLDEST decade available,
     * Mainstream picks randomly among every decade except the newest one
     * (chart() already supplies the newest-decade/Current case naturally,
     * so word-search's job here is specifically to reach further back).
     * Null (or Current) keeps the original fully-random decade pick.
     */
    private function randomSearchQuery(SongFilter $filter, ?SongEra $seekingEra = null): string
    {
        // Searching a known artist directly is far more reliable than a
        // generic word plus a bias term - see class docblock.
        if ($filter->genre === SongGenre::GermanRap) {
            return self::GERMAN_RAP_SEARCH_TERMS[array_rand(self::GERMAN_RAP_SEARCH_TERMS)];
        }

        $term = self::SEARCH_TERMS[array_rand(self::SEARCH_TERMS)];
        $decades = $this->decadeCandidates($filter);
        $decadeStart = match ($seekingEra) {
            SongEra::Classic => min($decades),
            SongEra::Mainstream => $this->decadeExcludingNewest($decades),
            default => $decades[array_rand($decades)],
        };

        $biasTerms = self::GENRE_BIAS_TERMS[$filter->genre->value] ?? null;
        $bias = $biasTerms ? ' '.$biasTerms[array_rand($biasTerms)] : '';

        return "{$term} {$decadeStart}{$bias}";
    }

    /**
     * @param  array<int, int>  $decades
     */
    private function decadeExcludingNewest(array $decades): int
    {
        $older = array_values(array_filter($decades, fn (int $decade) => $decade !== max($decades)));

        return $older !== [] ? $older[array_rand($older)] : max($decades);
    }
}
