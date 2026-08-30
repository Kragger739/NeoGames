<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Season XP
    |--------------------------------------------------------------------------
    | Season XP is earned on game finish, equal to the placement XP already
    | awarded to account XP (config/leveling.php) times this multiplier.
    */
    'xp_multiplier' => (float) env('SEASON_XP_MULTIPLIER', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Tier thresholds
    |--------------------------------------------------------------------------
    | Season XP required to *reach* tier N (1-indexed). Crossing a threshold
    | grants that tier's free 'track' cosmetic. 10 tiers.
    | Rough pace at 1:1 with placement XP: participation=10, 3rd=25, 2nd=35,
    | 1st=50 per game -> tier 1 in ~2-6 games.
    */
    'tier_thresholds' => [60, 150, 270, 420, 600, 820, 1080, 1380, 1720, 2100],

    /*
    |--------------------------------------------------------------------------
    | Leaderboard
    |--------------------------------------------------------------------------
    */
    'leaderboard_cache_seconds' => 60,
    'leaderboard_top_n' => 50,

    /*
    |--------------------------------------------------------------------------
    | Seeder
    |--------------------------------------------------------------------------
    | Only used by SeasonSeeder to set Season 1's ends_at.
    */
    'season_length_days' => 42,
];
