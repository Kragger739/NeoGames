<?php

namespace App\Models;

use Database\Factories\IconicArtistSongFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One track in an Iconic Artist's curated catalogue. `rank` is popularity
 * order (1 = biggest); an iconic game plays only `rank <= 20`. `song_id`
 * is set once the track's iTunes preview has been resolved into a playable
 * `songs` row; `unplayable` marks the ones iTunes couldn't match.
 */
class IconicArtistSong extends Model
{
    /** @use HasFactory<IconicArtistSongFactory> */
    use HasFactory;

    protected $fillable = [
        'iconic_artist_id',
        'provider_track_id',
        'title',
        'artist',
        'album_art_url',
        'release_year',
        'popularity',
        'rank',
        'preview_url',
        'song_id',
        'unplayable',
    ];

    protected function casts(): array
    {
        return [
            'release_year' => 'integer',
            'popularity' => 'integer',
            'rank' => 'integer',
            'unplayable' => 'boolean',
        ];
    }

    public function iconicArtist(): BelongsTo
    {
        return $this->belongsTo(IconicArtist::class);
    }

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }
}
