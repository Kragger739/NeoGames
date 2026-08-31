<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's single attempt at one day's Daily challenge. Created when they
 * start it (the UNIQUE(daily_challenge_id, user_id) enforces one per day);
 * the result columns are filled in when the game finishes.
 */
class DailyChallengeAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'daily_challenge_id', 'user_id', 'room_id',
        'correct_count', 'score', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'correct_count' => 'integer',
            'score' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function dailyChallenge(): BelongsTo
    {
        return $this->belongsTo(DailyChallenge::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'room_id');
    }
}
