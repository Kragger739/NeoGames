<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DdfPlayerState extends Model
{
    protected $fillable = [
        'room_player_id',
        'hearts',
        'is_eliminated',
        'eliminated_at',
        'is_camera_ready',
    ];

    protected function casts(): array
    {
        return [
            'is_eliminated' => 'boolean',
            'eliminated_at' => 'datetime',
            'is_camera_ready' => 'boolean',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }
}
