<?php

namespace App\Models;

use App\Enums\GameMode;
use App\Enums\SongGenre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The player level required to reach one gate - hosting a game night, a
 * game mode, or a genre. Keyed by string ("game_night", "mode:battle_royale",
 * "genre:pop", ...); a key with no row means "no lock" (level 1). Edited from
 * the admin dashboard; the resolved map is cached forever and busted on write.
 */
class UnlockRequirement extends Model
{
    protected $fillable = ['key', 'required_level'];

    protected function casts(): array
    {
        return ['required_level' => 'integer'];
    }

    private const CACHE_KEY = 'unlock_requirements:map';

    /**
     * Every gate key the app knows about, in display order, mapped to a
     * [label, category] pair. Genres mirror the user-pickable SONG_GENRES on
     * the client - "iconic" is Classic-internal and never gated.
     *
     * @return array<string, array{label: string, category: string}>
     */
    public static function labels(): array
    {
        $out = [
            'game_night' => ['label' => 'Host a game night', 'category' => 'game_night'],
        ];

        foreach (GameMode::cases() as $mode) {
            $out['mode:'.$mode->value] = [
                'label' => str($mode->value)->headline()->toString(),
                'category' => 'mode',
            ];
        }

        $genres = [
            SongGenre::Normal, SongGenre::Pop, SongGenre::HipHop, SongGenre::GermanRap,
            SongGenre::Artist, SongGenre::MultiArtist, SongGenre::Classics, SongGenre::Year,
        ];

        foreach ($genres as $genre) {
            $out['genre:'.$genre->value] = [
                'label' => str($genre->value)->headline()->toString(),
                'category' => 'genre',
            ];
        }

        return $out;
    }

    /**
     * key => required_level for every configured row (cached).
     *
     * @return array<string, int>
     */
    public static function map(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => self::query()->pluck('required_level', 'key')->map(fn ($l) => (int) $l)->all(),
        );
    }

    public static function levelFor(string $key): int
    {
        return self::map()[$key] ?? 1;
    }

    protected static function booted(): void
    {
        $bust = fn () => Cache::forget(self::CACHE_KEY);

        static::saved($bust);
        static::deleted($bust);
    }
}
