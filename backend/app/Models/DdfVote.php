<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DdfVote extends Model
{
    protected $fillable = [
        'game_room_id',
        'voting_round_number',
        'voter_room_player_id',
        'target_room_player_id',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'game_room_id');
    }

    public function voter(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'voter_room_player_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'target_room_player_id');
    }
}
