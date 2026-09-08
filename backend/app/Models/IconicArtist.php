<?php

namespace App\Models;

use App\Jobs\FetchIconicArtistCatalogue;
use Database\Factories\IconicArtistFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A curated act for the Songle "Iconic Artist series" carousel. Picking one
 * spins up a genre=artist room whose `artist_name` is this row's `name`, so
 * `name` must match `songs.artist` exactly (case-insensitively).
 */
class IconicArtist extends Model
{
    /** @use HasFactory<IconicArtistFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'image_path',
        'enabled',
        'sort_order',
        'fetch_status',
        'fetched_total',
        'fetched_playable',
        'fetch_error',
        'fetched_at',
        'fetch_run_token',
        'fetch_cursor',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort_order' => 'integer',
            'fetched_total' => 'integer',
            'fetched_playable' => 'integer',
            'fetched_at' => 'datetime',
            'fetch_cursor' => 'array',
        ];
    }

    public function songs(): HasMany
    {
        return $this->hasMany(IconicArtistSong::class);
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

    /**
     * Fetched catalogue rows that are playable and inside the game's
     * top-N-by-popularity window (an iconic game only ever draws from here).
     */
    public function playableTopCount(int $topRank = 20): int
    {
        return $this->songs()
            ->whereNotNull('song_id')
            ->where('unplayable', false)
            ->whereNotNull('rank')
            ->where('rank', '<=', $topRank)
            ->count();
    }

    /**
     * Reset fetch progress, bump the run token (so any in-flight chain
     * bails), and dispatch a fresh self-chaining catalogue fetch.
     * `afterResponse` so a `sync` dev queue doesn't stall the HTTP response.
     */
    public function startCatalogueFetch(): void
    {
        $token = (string) Str::uuid();

        $this->forceFill([
            'fetch_status' => 'pending',
            'fetch_error' => null,
            'fetch_cursor' => null,
            'fetch_run_token' => $token,
        ])->save();

        FetchIconicArtistCatalogue::dispatch($this->id, $token)->afterResponse();
    }
}
