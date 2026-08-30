<?php

namespace App\Models;

use App\Enums\SongGenre;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A curated Spotify playlist that feeds one genre's song pool, managed from
 * the admin dashboard. `songs:sync` reads these instead of an env var.
 */
class SeedPlaylist extends Model
{
    protected $fillable = ['genre', 'spotify_playlist_id', 'label'];

    protected function genre(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => SongGenre::from($value),
            set: fn (SongGenre|string $value) => $value instanceof SongGenre ? $value->value : $value,
        );
    }

    /**
     * @return array<int, string>  spotify playlist ids configured for $genre
     */
    public static function idsFor(SongGenre $genre): array
    {
        return self::query()->where('genre', $genre->value)->pluck('spotify_playlist_id')->all();
    }

    /**
     * @return array<int, SongGenre>  genres that have at least one playlist
     */
    public static function configuredGenres(): array
    {
        return self::query()->select('genre')->distinct()->get()
            ->map(fn (SeedPlaylist $p) => $p->genre)
            ->all();
    }
}
