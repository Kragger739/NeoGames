<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seed playlists
    |--------------------------------------------------------------------------
    |
    | Songle's song pool is no longer discovered live per room - the
    | `songs:sync` artisan command pre-populates the local `songs` table from
    | these Spotify playlists (track list + popularity), resolving each
    | track's 30-second preview + artwork through Apple's iTunes Search API.
    | Round-time selection then reads only from the DB.
    |
    | Use PUBLIC, USER-MADE playlists. Spotify's own editorial / algorithmic
    | playlists ("Today's Top Hits", "RapCaviar", "This Is <artist>", Discover
    | Weekly, ...) return 404 from the Web API since Spotify's Nov 2024
    | changes and cannot be used here.
    |
    | Accepts a bare base-62 playlist id or a full open.spotify.com URL. Keys
    | are SongGenre values; Artist / MultiArtist are not listed (they source
    | live from the named artist's Spotify top tracks). German Rap can use a
    | playlist and/or the artist term pool below.
    |
    */

    'seed_playlists' => [
        'normal' => array_filter(explode(',', (string) env('MUSIC_PLAYLISTS_NORMAL', ''))),
        'pop' => array_filter(explode(',', (string) env('MUSIC_PLAYLISTS_POP', ''))),
        'hip_hop' => array_filter(explode(',', (string) env('MUSIC_PLAYLISTS_HIP_HOP', ''))),
        'german_rap' => array_filter(explode(',', (string) env('MUSIC_PLAYLISTS_GERMAN_RAP', ''))),
        'classics' => array_filter(explode(',', (string) env('MUSIC_PLAYLISTS_CLASSICS', ''))),
        'iconic' => array_filter(explode(',', (string) env('MUSIC_PLAYLISTS_ICONIC', ''))),
        'year' => array_filter(explode(',', (string) env('MUSIC_PLAYLISTS_YEAR', ''))),
    ],

    /*
    | German Rap has no reliable single playlist; `songs:sync` also resolves
    | each of these artist names via Spotify and pulls their top tracks. Kept
    | here (not a service constant) so it can be tuned without a deploy.
    */
    'german_rap_artists' => [
        'Bushido', 'Sido', 'Kollegah', 'Haftbefehl', 'Capital Bra',
        'Ufo361', 'RAF Camora', 'Bonez MC', 'Kontra K', 'Luciano',
        'Apache 207', 'Shirin David', 'Cro', 'Marteria', 'Casper',
    ],

    /*
    |--------------------------------------------------------------------------
    | iTunes Search API throttle
    |--------------------------------------------------------------------------
    |
    | The iTunes Search API rate-limits at roughly 20 requests/minute and
    | returns HTTP 403 on bursts. `songs:sync` sleeps this long between
    | preview lookups. Not used at round time (the pool is pre-seeded).
    |
    */

    'itunes_throttle_ms' => (int) env('MUSIC_ITUNES_THROTTLE_MS', 3200),

    /*
    | Storefront for iTunes Search + Spotify market for track relinking.
    */
    'itunes_country' => env('MUSIC_ITUNES_COUNTRY', 'US'),
    'spotify_market' => env('MUSIC_SPOTIFY_MARKET', 'US'),

    /*
    | Optional: a directory of `<genre>.txt` files, one "Artist - Title" per
    | line, used when `songs:sync --source=list` is passed instead of the
    | Spotify playlists above. Each line is resolved via Spotify search (for
    | popularity) then iTunes (for the preview).
    */
    'curated_list_path' => storage_path('app/song-seeds'),

];
