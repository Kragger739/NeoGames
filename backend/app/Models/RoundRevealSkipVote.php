<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's vote to cut the between-rounds reveal short. When more than
 * half of a room's active players have voted for the same round, the next
 * round starts immediately instead of waiting out REVEAL_DELAY_SECONDS.
 */
class RoundRevealSkipVote extends Model
{
    protected $fillable = [
        'round_id',
        'room_player_id',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }
}
