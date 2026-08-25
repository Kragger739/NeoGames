<?php

namespace App\Models;

use Database\Factories\GuessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guess extends Model
{
    /** @use HasFactory<GuessFactory> */
    use HasFactory;

    protected $fillable = [
        'round_id',
        'player_id',
        'guess_text',
        'correct',
        'snippet_stage_at_guess',
    ];

    protected function casts(): array
    {
        return [
            'correct' => 'boolean',
            'snippet_stage_at_guess' => 'float',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'player_id');
    }
}
