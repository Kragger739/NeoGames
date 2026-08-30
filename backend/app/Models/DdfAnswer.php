<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DdfAnswer extends Model
{
    protected $fillable = [
        'game_room_id',
        'ddf_question_id',
        'room_player_id',
        'question_number',
        'answer_text',
        'submitted_at',
        'is_correct',
        'marked_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'is_correct' => 'boolean',
            'marked_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'game_room_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(DdfQuestion::class, 'ddf_question_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }
}
