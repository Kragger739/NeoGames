<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DubClipCharacter extends Model
{
    protected $fillable = [
        'dub_clip_id',
        'key',
        'display_name',
        'color',
        'position',
    ];

    public function clip(): BelongsTo
    {
        return $this->belongsTo(DubClip::class, 'dub_clip_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DubClipLine::class);
    }
}
