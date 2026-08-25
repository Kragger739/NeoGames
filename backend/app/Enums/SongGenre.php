<?php

namespace App\Enums;

use App\Services\Deezer\DeezerClient;

enum SongGenre: string
{
    case Normal = 'normal';
    case Pop = 'pop';
    case HipHop = 'hip_hop';
    case GermanRap = 'german_rap';
    case Artist = 'artist';
    case Classics = 'classics';
    case Year = 'year';

    /**
     * Deezer's genre-scoped chart ID for chart-sourced discovery (Easy/
     * Intermediate/Medium - see SongDiscoveryService). Null means "no genre
     * chart constraint" - Normal, Classics, and Year only ever filter by
     * release year, never genre. GermanRap is also null: Deezer's public
     * /genre list has no language-specific subgenre (only broad top-level
     * ones like the generic global Rap/Hip Hop chart, confirmed live -
     * that chart is dominated by English-language content, not German), so
     * it's discovered via a dedicated word-search term pool instead - see
     * SongDiscoveryService::GERMAN_RAP_SEARCH_TERMS. Artist is null too - it
     * always sources from that one artist's own top tracks
     * (SongDiscoveryService::discoverFromArtist()), never a chart at all.
     */
    public function deezerGenreId(): ?int
    {
        return match ($this) {
            self::Pop => DeezerClient::GENRE_ID_POP,
            self::HipHop => DeezerClient::GENRE_ID_HIP_HOP,
            default => null,
        };
    }

    /**
     * The value cache()/scopeMatchingFilter() write/match against on the
     * songs.genre column. Null for genres with no discovery-time chart/
     * search facet - those never write or require a genre tag at all.
     * Artist is deliberately null here too, even though it does have its
     * own discovery facet: matching happens against the existing
     * songs.artist column directly (see Song::scopeMatchingFilterIgnoringPopularity()),
     * so it needs no separate genre-column tag.
     */
    public function cacheTag(): ?string
    {
        return match ($this) {
            self::Pop, self::HipHop, self::GermanRap => $this->value,
            default => null,
        };
    }
}
