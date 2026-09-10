<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DubRoleAssignment extends Model
{
    protected $fillable = [
        'dub_game_id',
        'dub_clip_character_id',
        'room_player_id',
        'round_number',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(DubGame::class, 'dub_game_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(DubClipCharacter::class, 'dub_clip_character_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }
}
