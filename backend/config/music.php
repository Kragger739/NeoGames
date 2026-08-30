<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Song pool
    |--------------------------------------------------------------------------
    |
    | Songle's pool is not discovered live per room - `php artisan songs:sync`
    | pre-populates the local `songs` table from curated Spotify playlists
    | (track list + popularity), caching each track's 30-second preview via
    | Apple's iTunes Search API. Round-time selection then reads only the DB.
    |
    | The playlists themselves are managed from the admin dashboard
    | (seed_playlists table), not here - use PUBLIC, USER-MADE playlists:
    | Spotify's own editorial / algorithmic playlists ("Today's Top Hits",
    | "RapCaviar", "This Is <artist>", ...) return 404 from the Web API since
    | Spotify's Nov 2024 changes.
    |
    */

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

];
