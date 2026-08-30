<?php

namespace App\Models;

use App\Enums\DdfGameState;
use App\Enums\DdfLanguage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DdfGame extends Model
{
    protected $fillable = [
        'game_room_id',
        'state',
        'state_version',
        'stage_started_at',
        'rounds_per_voting',
        'language',
        'rounds_played_this_cycle',
        'question_timer_seconds',
        'voting_timer_seconds',
        'current_question_id',
        'current_question_number',
        'voting_round_number',
        'is_paused',
        'paused_remaining_seconds',
        'is_revote',
        'tie_candidate_player_ids',
        'winner_room_player_id',
        'current_turn_room_player_id',
        'couch_mode',
        'dataset_id',
        'safe_mode',
        'cycle_started_question_number',
    ];

    protected function casts(): array
    {
        return [
            'state' => DdfGameState::class,
            'language' => DdfLanguage::class,
            'stage_started_at' => 'datetime',
            'is_paused' => 'boolean',
            'is_revote' => 'boolean',
            'tie_candidate_player_ids' => 'array',
            'couch_mode' => 'boolean',
            'safe_mode' => 'boolean',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'game_room_id');
    }

    public function currentQuestion(): BelongsTo
    {
        return $this->belongsTo(DdfQuestion::class, 'current_question_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'winner_room_player_id');
    }

    public function turnPlayer(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'current_turn_room_player_id');
    }

    /** The custom question set this game draws from, or null for the built-in pool. */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
