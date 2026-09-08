<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NeoCoins per level
    |--------------------------------------------------------------------------
    |
    | Credited once for every level a user gains (LevelingService::award()).
    | Also the unit battle-pass tier coin rewards are authored in. Kept low on
    | purpose - the level-up ledger keys each grant by "level_up:{id}:{lvl}",
    | so re-tuning this only affects levels gained after the change.
    |
    */

    'per_level' => (int) env('NEOCOINS_PER_LEVEL', 50),

];
