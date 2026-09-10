<?php

namespace App\Models;

use App\Enums\DubGameState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DubGame extends Model
{
    protected $fillable = [
        'game_room_id',
        'state',
        'state_version',
        'stage_started_at',
        'dub_clip_id',
        'played_clip_ids',
        'round_number',
        'current_line_index',
        'line_timer_seconds',
        'watch_timer_seconds',
        'assembled_video_path',
        'assembly_error',
        'assembly_started_at',
        'last_round_score',
        'total_score',
    ];

    protected function casts(): array
    {
        return [
            'state' => DubGameState::class,
            'stage_started_at' => 'datetime',
            'assembly_started_at' => 'datetime',
            'played_clip_ids' => 'array',
            'last_round_score' => 'float',
            'total_score' => 'float',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'game_room_id');
    }

    public function clip(): BelongsTo
    {
        return $this->belongsTo(DubClip::class, 'dub_clip_id');
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(DubRoleAssignment::class);
    }

    public function takes(): HasMany
    {
        return $this->hasMany(DubTake::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(DubRating::class);
    }
}
