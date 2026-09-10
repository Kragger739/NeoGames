<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DubClipLine extends Model
{
    protected $fillable = [
        'dub_clip_id',
        'dub_clip_character_id',
        'position',
        'start_ms',
        'end_ms',
        'text',
    ];

    public function clip(): BelongsTo
    {
        return $this->belongsTo(DubClip::class, 'dub_clip_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(DubClipCharacter::class, 'dub_clip_character_id');
    }
}
