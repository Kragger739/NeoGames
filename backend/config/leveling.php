<?php

return [

    /*
    |--------------------------------------------------------------------------
    | XP award amounts
    |--------------------------------------------------------------------------
    |
    | Awarded exactly once per game, when the whole game finishes, via
    | LevelingService::awardForGameFinish() - never per individual round.
    | Fixed amounts for the top 3 final placements; everyone else (4th
    | place and beyond) gets the flat participation amount instead.
    |
    */

    'xp_first' => 50,
    'xp_second' => 35,
    'xp_third' => 25,
    'xp_participation' => 10,

    /*
    |--------------------------------------------------------------------------
    | Level curve
    |--------------------------------------------------------------------------
    |
    | Triangular growth: XP required to *reach* level N is
    | coefficient * N * (N - 1). With the default coefficient (50):
    | level 2 = 100 XP, level 3 = 300, level 4 = 600, level 5 = 1000...
    |
    */

    'level_curve_coefficient' => 50,

    /*
    |--------------------------------------------------------------------------
    | Mode unlocks
    |--------------------------------------------------------------------------
    |
    | Battle Royale requires the host to have reached this level - see
    | App\Rules\BattleRoyaleRequiresLevel. A brand-new account (level 1)
    | plays its first few games in Classic/Solo before this unlocks; that's
    | the intended shape of the gate, not something to work around.
    |
    */

    'battle_royale_min_level' => 3,

];
