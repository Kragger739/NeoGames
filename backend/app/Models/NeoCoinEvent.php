<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the append-only NeoCoins ledger. `amount` is signed (+ credit,
 * - debit); `dedup_key` is set only for idempotent credits, guarded by
 * UNIQUE(user_id, dedup_key). Written by NeoCoinService, never directly.
 */
class NeoCoinEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'amount', 'reason', 'dedup_key', 'meta'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
