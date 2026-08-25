<?php

namespace App\Models;

use App\Enums\DifficultyTier;
use App\Enums\RoundStatus;
use Database\Factories\RoundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    /** @use HasFactory<RoundFactory> */
    use HasFactory;

    protected $fillable = [
        'room_id',
        'song_id',
        'tier',
        'snippet_stage',
        'stage_started_at',
        'status',
        'winning_player_id',
        'stage_version',
    ];

    protected function casts(): array
    {
        return [
            'tier' => DifficultyTier::class,
            'status' => RoundStatus::class,
            'snippet_stage' => 'float',
            'stage_started_at' => 'datetime',
            'stage_version' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'room_id');
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    public function winningPlayer(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'winning_player_id');
    }

    public function guesses(): HasMany
    {
        return $this->hasMany(Guess::class);
    }

    /**
     * Distinct player ids with at least one correct guess on this round -
     * used by Battle Royale to decide who survives when the round closes.
     */
    public function correctGuesserIds(): \Illuminate\Support\Collection
    {
        return $this->guesses()->where('correct', true)->distinct()->pluck('player_id');
    }
}
