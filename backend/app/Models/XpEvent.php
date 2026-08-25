<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only leveling ledger - one row per (round, user, type) award.
 * The UNIQUE(round_id, user_id, type) constraint, not application logic
 * alone, is what makes double-awarding physically impossible.
 */
class XpEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'round_id',
        'room_id',
        'type',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'room_id');
    }
}
