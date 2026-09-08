<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The stored free-rotation pick for one ISO week. See
 * IconicArtist::freeThisWeek().
 */
class IconicFreeWeek extends Model
{
    protected $fillable = ['week_key', 'iconic_artist_id'];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(IconicArtist::class, 'iconic_artist_id');
    }
}
