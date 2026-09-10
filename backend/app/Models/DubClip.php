<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DubClip extends Model
{
    /** Confetti-Pop hue tokens a character can own (see index.css). */
    public const HUES = ['grape', 'turquoise', 'coral', 'sunflower', 'bubblegum'];

    protected $fillable = [
        'source',
        'created_by_user_id',
        'game_room_id',
        'dataset_id',
        'position',
        'title',
        'status',
        'is_public',
        'source_video_path',
        'source_url',
        'music_bed_path',
        'duration_ms',
        'clip_start_ms',
        'clip_end_ms',
        'video_width',
        'video_height',
        'video_fps',
        'processing_job_id',
        'processing_error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function characters(): HasMany
    {
        return $this->hasMany(DubClipCharacter::class)->orderBy('position');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DubClipLine::class)->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'game_room_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isFromLink(): bool
    {
        return $this->source_url !== null;
    }
}
