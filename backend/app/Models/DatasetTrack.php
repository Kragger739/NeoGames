<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetTrack extends Model
{
    protected $fillable = [
        'dataset_id',
        'provider_track_id',
        'title',
        'artist',
        'album_art_url',
        'preview_url',
        'position',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
