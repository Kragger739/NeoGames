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
    case MultiArtist = 'multi_artist';
    case Iconic = 'iconic';

    /** Safety cap on how many artists a MultiArtist room can list - no product-level limit, just a sane technical max. */
    public const MAX_MULTI_ARTIST_COUNT = 10;

    /**
     * Deezer's genre-scoped chart ID for chart-sourced discovery (Easy/
     * Intermediate/Medium - see SongDiscoveryService). Null means "no genre
     * chart constraint" - Normal, Classics, and Year only ever filter by
     * release year, never genre. GermanRap is also null: Deezer's public
     * /genre list has no language-specific subgenre (only broad top-level
     * ones like the generic global Rap/Hip Hop chart, confirmed live -
     * that chart is dominated by English-language content, not German), so
     * it's discovered via a dedicated word-search term pool instead - see
     * SongDiscoveryService::GERMAN_RAP_SEARCH_TERMS. Artist and MultiArtist
     * are null too - they always source from the named artist(s)' own top
     * tracks (SongDiscoveryService::ensureArtistPoolReady()), never a chart.
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
     * Artist and MultiArtist are deliberately null here too, even though
     * they do have their own discovery facet: matching happens against the
     * existing songs.artist column directly (see
     * Song::scopeMatchingFilterIgnoringPopularity()), so neither needs a
     * separate genre-column tag.
     */
    public function cacheTag(): ?string
    {
        return match ($this) {
            self::Pop, self::HipHop, self::GermanRap, self::Iconic => $this->value,
            default => null,
        };
    }

    /**
     * The curated Deezer playlists Iconic is seeded from - "Top 100 most
     * recognizable songs of all-time" plus a set of year/decade and
     * German-market "hits" playlists, all merged into one shared pool (see
     * SongDiscoveryService::discoverFromPlaylist()). Empty for every other
     * genre, which have no fixed playlist source.
     *
     * @return array<int, string>
     */
    public function deezerPlaylistIds(): array
    {
        return match ($this) {
            self::Iconic => [
                '11471414844', // Top 100 most recognizable songs of all-time
                '5339620562',  // Top Hits 2010
                '13650084141', // 20s Hits
                '1283499335',  // Top Hits 2019
                '13013668743', // 10s Film Hits
                '5132762464',  // Top Hits 2018
                '10396828062', // Deutschland, deine Hits - 10er
                '12345421311', // Top Hits 2021
                '13693726841', // Top Hits 2024
                '11909413461', // 2023 Deutschpop
            ],
            default => [],
        };
    }
}
