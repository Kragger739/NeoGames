<?php

namespace App\Models;

use App\Jobs\FetchIconicArtistCatalogue;
use Database\Factories\IconicArtistFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
        'price',
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
            'price' => 'integer',
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

    /** Users who have unlocked this artist (only meaningful when price > 0). */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'iconic_artist_user')
            ->withPivot('source', 'acquired_at');
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

    /**
     * The one priced artist that is free to play this ISO week. The pick is
     * decided once per week and stored (iconic_free_weeks), so it stays put
     * for the whole week even if an admin adds / disables / re-prices /
     * reorders artists - it only rotates on the Monday boundary.
     *
     * When first deciding a week, it advances to the priced artist that
     * follows the previous week's pick, cyclically, in (sort_order, id)
     * order. Self-heals only if the stored pick itself becomes invalid
     * (deleted, disabled, or made free) during its week.
     *
     * Access is temporal: being the pick lets anyone start this artist without
     * owning it (see IconicArtistController), but grants no permanent unlock.
     */
    public static function freeThisWeek(): ?self
    {
        $pool = static::query()
            ->where('enabled', true)
            ->where('price', '>', 0)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($pool->isEmpty()) {
            return null;
        }

        $weekKey = now('UTC')->format('o-\WW'); // e.g. "2026-W02"

        $row = IconicFreeWeek::firstWhere('week_key', $weekKey);

        if ($row) {
            $stored = $pool->firstWhere('id', $row->iconic_artist_id);
            if ($stored) {
                return $stored;
            }
        }

        // Decide (or re-decide) this week's pick: the priced artist after
        // whichever one was picked most recently, cyclically.
        $lastId = IconicFreeWeek::orderByDesc('id')->value('iconic_artist_id');
        $lastIdx = $lastId ? $pool->search(fn (self $a) => $a->id === $lastId) : false;
        $pick = $pool[$lastIdx === false ? 0 : ($lastIdx + 1) % $pool->count()];

        if ($row) {
            $row->update(['iconic_artist_id' => $pick->id]);
        } else {
            // createOrFirst is race-safe (unique week_key); if another request
            // won, take its pick so the week stays consistent.
            $row = IconicFreeWeek::createOrFirst(
                ['week_key' => $weekKey],
                ['iconic_artist_id' => $pick->id],
            );
            $winner = $pool->firstWhere('id', $row->iconic_artist_id);
            if ($winner) {
                return $winner;
            }
        }

        return $pick;
    }

    /** Monday 00:00 UTC that ends the current free-artist week. */
    public static function freeWeekEndsAt(): Carbon
    {
        return now('UTC')->startOfWeek(Carbon::MONDAY)->addWeek();
    }
}
