<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonProgress extends Model
{
    protected $table = 'season_progress';

    protected $fillable = ['season_id', 'user_id', 'xp', 'current_tier', 'has_pass'];

    protected function casts(): array
    {
        return [
            'xp' => 'integer',
            'current_tier' => 'integer',
            'has_pass' => 'boolean',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
