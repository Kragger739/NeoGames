<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DubPlayerState extends Model
{
    protected $fillable = [
        'room_player_id',
        'mic_ready',
        'has_downloaded',
    ];

    protected function casts(): array
    {
        return [
            'mic_ready' => 'boolean',
            'has_downloaded' => 'boolean',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }
}
