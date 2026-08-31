<?php

namespace App\Models;

use App\Enums\SongGenre;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

/**
 * One day's fixed five songs for the Daily challenge - the same set for
 * everyone, generated deterministically from the date the first time that
 * day is requested, unless an admin has curated it by hand.
 */
class DailyChallenge extends Model
{
    public const SONG_COUNT = 5;

    protected $fillable = ['date', 'song_ids', 'curated'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'song_ids' => 'array',
            'curated' => 'boolean',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(DailyChallengeAttempt::class);
    }

    /**
     * The challenge for a given day, created (with a deterministic song set)
     * on first access. The generated set is then stored, so it stays stable
     * for the rest of that day even if the song pool changes underneath it.
     */
    public static function forDate(CarbonInterface $date): self
    {
        $key = $date->toDateString();

        if ($existing = static::whereDate('date', $key)->first()) {
            return $existing;
        }

        try {
            return static::create([
                'date' => $key,
                'song_ids' => static::generateSongIds($date),
                'curated' => false,
            ]);
        } catch (QueryException $e) {
            // Lost a race to create the same day - re-read.
            $row = static::whereDate('date', $key)->first();

            if ($row === null) {
                throw $e;
            }

            return $row;
        }
    }

    /**
     * A date-seeded pick of SONG_COUNT songs from the curated "iconic" pool
     * (the recognizable, Classic-mode set), widening to the whole pool only
     * if iconic is too small. Same date -> same five, every time.
     *
     * @return array<int, int>
     */
    protected static function generateSongIds(CarbonInterface $date): array
    {
        $pool = Song::query()
            ->where('genre', SongGenre::Iconic->value)
            ->where('excluded', false)
            ->pluck('id')->all();

        if (count($pool) < self::SONG_COUNT) {
            $pool = Song::query()->where('excluded', false)->pluck('id')->all();
        }

        mt_srand(crc32('daily:'.$date->toDateString()));
        shuffle($pool);
        mt_srand();

        return array_map('intval', array_slice($pool, 0, self::SONG_COUNT));
    }

    /**
     * The challenge's songs, in the stored order.
     *
     * @return Collection<int, Song>
     */
    public function songs(): Collection
    {
        $order = array_flip($this->song_ids);

        return Song::query()->whereIn('id', $this->song_ids)->get()
            ->sortBy(fn (Song $song) => $order[$song->id] ?? PHP_INT_MAX)
            ->values();
    }
}
