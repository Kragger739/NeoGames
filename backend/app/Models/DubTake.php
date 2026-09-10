<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DubTake extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'dub_game_id',
        'dub_clip_line_id',
        'room_player_id',
        'round_number',
        'audio_path',
        'duration_ms',
        'is_selected',
    ];

    protected function casts(): array
    {
        return [
            'is_selected' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(DubGame::class, 'dub_game_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(DubClipLine::class, 'dub_clip_line_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }
}
