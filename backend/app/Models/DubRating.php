<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DubRating extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'dub_game_id',
        'room_player_id',
        'round_number',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(DubGame::class, 'dub_game_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }
}
