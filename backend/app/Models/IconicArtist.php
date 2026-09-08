<?php

namespace App\Models;

use Database\Factories\IconicArtistFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A curated act for the Songle "Iconic Artist series" carousel. Picking one
 * spins up a genre=artist room whose `artist_name` is this row's `name`, so
 * `name` must match `songs.artist` exactly (case-insensitively).
 */
class IconicArtist extends Model
{
    /** @use HasFactory<IconicArtistFactory> */
    use HasFactory;

    protected $fillable = ['name', 'image_path', 'enabled', 'sort_order'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Host-relative URL to the uploaded photo, or null. Same origin-relative
     * shape as Cosmetic::imageUrl / User::avatarUrl so it survives a tunnel.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->image_path
                ? parse_url(Storage::disk('public')->url($this->image_path), PHP_URL_PATH)
                : null,
        );
    }

    /**
     * Playable pool songs for this exact act - case-insensitive on the
     * artist display-name column, matching the Artist branch of
     * Song::scopeMatchingFilterIgnoringPopularity(). Admin-screen only: a
     * low count warns that Start will fall back to repeats (or fail at 0).
     * The real discovery pool also applies a release-year floor, so the
     * effective count can be a touch lower than this.
     */
    public function poolCount(): int
    {
        return Song::query()
            ->where('excluded', false)
            ->whereRaw('LOWER(artist) = ?', [mb_strtolower(trim($this->name))])
            ->count();
    }
}
