<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rung of a season's battlepass ladder: the season XP needed to reach it,
 * a free reward cosmetic, and an optional premium ("pass") reward. Managed
 * from the admin dashboard (AdminSeasonController::syncTiers).
 */
class SeasonTier extends Model
{
    protected $fillable = [
        'season_id', 'tier', 'xp_threshold', 'free_cosmetic_id', 'premium_cosmetic_id',
    ];

    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'xp_threshold' => 'integer',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function free(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class, 'free_cosmetic_id');
    }

    public function premium(): BelongsTo
    {
        return $this->belongsTo(Cosmetic::class, 'premium_cosmetic_id');
    }
}
