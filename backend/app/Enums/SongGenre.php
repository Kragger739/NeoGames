<?php

namespace App\Enums;

enum SongGenre: string
{
    case Normal = 'normal';
    case Pop = 'pop';
    case HipHop = 'hip_hop';
    case GermanRap = 'german_rap';
    case Artist = 'artist';
    case Classics = 'classics';
    case Year = 'year';
    case MultiArtist = 'multi_artist';
    case Iconic = 'iconic';

    /** Safety cap on how many artists a MultiArtist room can list - no product-level limit, just a sane technical max. */
    public const MAX_MULTI_ARTIST_COUNT = 10;

    /**
     * The value written to / matched against the songs.genre column. Null for
     * genres with no genre facet: Normal / Classics / Year filter only by
     * release year, and Artist / MultiArtist match on the songs.artist column
     * directly (see Song::scopeMatchingFilterIgnoringPopularity()), so none of
     * them need a tag.
     */
    public function cacheTag(): ?string
    {
        return match ($this) {
            self::Pop, self::HipHop, self::GermanRap, self::Iconic => $this->value,
            default => null,
        };
    }

    /**
     * The curated Spotify playlists `songs:sync` seeds this genre's pool
     * from, taken from config/music.php (env-driven). Empty for Artist /
     * MultiArtist (they source live from the named artist's Spotify top
     * tracks) and for any genre left unconfigured.
     *
     * @return array<int, string>
     */
    public function spotifyPlaylistIds(): array
    {
        return array_values(config('music.seed_playlists.'.$this->value, []));
    }

    /**
     * Whether this genre is seeded per-room from live artist top tracks
     * rather than from a fixed playlist pool.
     */
    public function isArtistSourced(): bool
    {
        return $this === self::Artist || $this === self::MultiArtist;
    }
}
