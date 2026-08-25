<?php

namespace App\Enums;

/**
 * Fixed, absolute-year era classification a song's release_year falls
 * into - used to bias a room's song selection toward the target mix
 * (config('songs.era_*_share')) rather than just whatever's trending this
 * week. Deliberately not relative to a room's own genre/year settings: a
 * Classics-only room's pool is already almost entirely Classic-bucketed, so
 * the mix-picker simply can't find Current-bucket candidates there and
 * gracefully falls back to whatever's actually available - the thresholds
 * themselves never need to shift per room.
 */
enum SongEra: string
{
    case Classic = 'classic';
    case Mainstream = 'mainstream';
    case Current = 'current';

    public static function fromReleaseYear(int $releaseYear): self
    {
        $currentYear = (int) now()->year;

        if ($releaseYear >= $currentYear - (int) config('songs.recent_years_window')) {
            return self::Current;
        }

        if ($releaseYear <= $currentYear - (int) config('songs.classic_years_threshold')) {
            return self::Classic;
        }

        return self::Mainstream;
    }

    /** Target share of a full game's songs this era should occupy - see config('songs.era_*_share'). */
    public function targetShare(): float
    {
        return match ($this) {
            self::Mainstream => (float) config('songs.era_mainstream_share'),
            self::Classic => (float) config('songs.era_classic_share'),
            self::Current => (float) config('songs.era_current_share'),
        };
    }
}
