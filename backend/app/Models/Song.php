<?php

namespace App\Models;

use App\Enums\DifficultyTier;
use App\Enums\SongEra;
use App\Enums\SongGenre;
use App\Support\SongFilter;
use Database\Factories\SongFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A shared cache of Deezer tracks discovered to have a playable preview,
 * not owned by any host. Populated automatically by SongDiscoveryService.
 * Popularity is derived from Deezer's own rank/chart-position signal.
 */
class Song extends Model
{
    /** @use HasFactory<SongFactory> */
    use HasFactory;

    protected $fillable = [
        'deezer_track_id',
        'title',
        'artist',
        'artist_deezer_id',
        'artist_fan_count',
        'preview_url',
        'album_art_url',
        'popularity',
        'release_year',
        'genre',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'popularity' => 'integer',
            'release_year' => 'integer',
            'artist_fan_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function difficultyTier(): ?DifficultyTier
    {
        return DifficultyTier::fromPopularity($this->popularity);
    }

    public function eraBucket(): ?SongEra
    {
        return $this->release_year === null ? null : SongEra::fromReleaseYear($this->release_year);
    }

    public function audioUrl(): string
    {
        return $this->preview_url;
    }

    /**
     * Single source of truth for "does this song satisfy a room's tier +
     * genre + release-year settings" - used by both SongDiscoveryService's
     * cache lookup and ExpandSongPool's pool-health check, so the two never
     * drift apart.
     */
    public function scopeMatchingFilter(Builder $query, SongFilter $filter): Builder
    {
        [$min, $max] = $filter->tier->popularityRange();
        $query->whereBetween('popularity', [$min, $max]);

        return $this->scopeMatchingFilterIgnoringPopularity($query, $filter);
    }

    /**
     * Same genre/release-year matching as scopeMatchingFilter(), but
     * without the tier's popularity band - used by SongDiscoveryService's
     * guaranteed-song fallback, which only ever relaxes popularity, never
     * genre/year (a Pop room must never be served a non-Pop song, and
     * Classics/Year's release-year bounds are the room's actual configured
     * intent, not a difficulty knob).
     */
    public function scopeMatchingFilterIgnoringPopularity(Builder $query, SongFilter $filter): Builder
    {
        $tag = $filter->genre->cacheTag();

        if ($tag !== null) {
            $query->where('genre', $tag);
        }

        if ($filter->genre === SongGenre::Artist && $filter->artistName !== null) {
            // Artist matches directly against the artist display-name
            // column, not a genre tag - see SongGenre::cacheTag().
            $query->whereRaw('LOWER(artist) = ?', [mb_strtolower(trim($filter->artistName))]);
        }

        return match ($filter->genre) {
            // Same relaxed floor as Classics - see SongDiscoveryService::discoveryYearFloor().
            SongGenre::Classics, SongGenre::Artist => $query->where('release_year', '>=', config('songs.classics_min_release_year')),
            SongGenre::Year => $query->whereBetween('release_year', [$filter->yearFrom, $filter->yearTo]),
            default => $query->where('release_year', '>=', config('songs.min_release_year')),
        };
    }

    /**
     * Mirrors SongEra::fromReleaseYear()'s thresholds as a query constraint,
     * since the era itself is a computed PHP value, not a stored column -
     * used by SongDiscoveryService's session-aware picker to target a
     * specific era bucket among otherwise-matching candidates.
     */
    public function scopeInEraBucket(Builder $query, SongEra $era): Builder
    {
        $currentYear = (int) now()->year;
        $currentThreshold = $currentYear - (int) config('songs.recent_years_window');
        $classicThreshold = $currentYear - (int) config('songs.classic_years_threshold');

        return match ($era) {
            SongEra::Current => $query->where('release_year', '>=', $currentThreshold),
            SongEra::Classic => $query->where('release_year', '<=', $classicThreshold),
            SongEra::Mainstream => $query->where('release_year', '>', $classicThreshold)
                ->where('release_year', '<', $currentThreshold),
        };
    }
}
