<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Background pool growth
    |--------------------------------------------------------------------------
    |
    | ExpandSongPool no-ops once a tier's cached pool reaches this size, so
    | steady-state cost is near zero once a tier is healthy. The cooldown
    | guards against several concurrently-dispatched jobs all attempting a
    | live discovery pass for the same tier at once.
    |
    */

    'min_pool_size' => 25,

    'expand_lock_seconds' => 600,

    /*
    | The hard cap on how many surviving candidates a single discovery pass
    | (discoverFromChart/Playlist/WordSearch -> processCandidates) will spend
    | Deezer calls on - two per candidate (trackDetails + artistFanCount).
    | Without it, a cold-cache round start processes a whole 50-track chart,
    | or all ten of Iconic's seed playlists at once, synchronously inside the
    | web request and blows past its execution-time limit. ExpandSongPool
    | tops the pool up to min_pool_size in the background afterward.
    */

    'discovery_pass_limit' => 20,

    /*
    |--------------------------------------------------------------------------
    | Release-year floors
    |--------------------------------------------------------------------------
    |
    | Every song played must have been released this year or later, by
    | default - min_release_year is today's baseline (Normal/Pop/Hip-hop).
    | Classics relaxes that floor so older, still-well-known hits can
    | surface; Year mode ignores both and uses the room's own [from, to]
    | range instead. Centralized here (not as a SongDiscoveryService
    | constant) so Song::scopeMatchingFilter() can read it without the
    | model depending on the service class.
    |
    */

    'min_release_year' => 2000,

    'classics_min_release_year' => 1950,

    /*
    |--------------------------------------------------------------------------
    | Popularity
    |--------------------------------------------------------------------------
    |
    | songs.popularity is Spotify's own 0-100 popularity score, stored as-is
    | by `songs:sync` (no blending). DifficultyTier::popularityRange()'s
    | bands gate on it directly. Spotify's scale is compressed - most catalog
    | tracks sit well under 60 and only current mega-hits reach the 80s+ - so
    | after the first sync, check the actual distribution and re-tune the
    | tier bands if a tier ends up starved.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Era mix
    |--------------------------------------------------------------------------
    |
    | Every room game targets this rough distribution across a full game's
    | songs (SongDiscoveryService's session-aware picker converges toward it
    | via deficit scheduling, not a hard per-pick rule): 60% Mainstream
    | (the broad middle), 25% Classic, 15% Current. See App\Enums\SongEra.
    |
    */

    'era_mainstream_share' => 0.60,

    'era_classic_share' => 0.25,

    'era_current_share' => 0.15,

    'recent_years_window' => 2,

    'classic_years_threshold' => 15,

    /*
    |--------------------------------------------------------------------------
    | Artist variety
    |--------------------------------------------------------------------------
    |
    | The session-aware picker avoids repeating an artist within one room's
    | game, unless every remaining candidate would require a repeat anyway -
    | at which point a repeat is only allowed for an "exceptionally popular"
    | artist, i.e. a song scoring at or above this recognizability threshold.
    |
    */

    'exceptional_artist_threshold' => 90,

];
