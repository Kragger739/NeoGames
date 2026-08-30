<?php

namespace App\Models;

use App\Enums\CosmeticSlot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

class Cosmetic extends Model
{
    public const CATALOG_CACHE_KEY = 'cosmetics:catalog';

    protected $fillable = ['slot', 'key', 'name', 'rarity', 'source', 'season_id', 'tier'];

    protected function casts(): array
    {
        return [
            'slot' => CosmeticSlot::class,
            'tier' => 'integer',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cosmetic_user')
            ->withPivot('source', 'acquired_at');
    }

    /**
     * The whole catalogue as a plain array keyed by id, cached briefly. The
     * catalogue is tiny (a couple dozen rows) and read on every avatar
     * serialization, so this keeps that to zero per-user queries. Seeders /
     * catalogue edits call forgetCatalog().
     *
     * @return array<int, array{id:int, slot:string, key:string, name:string, rarity:string, source:string, season_id:int|null, tier:int|null}>
     */
    public static function catalog(): array
    {
        return Cache::remember(self::CATALOG_CACHE_KEY, now()->addMinutes(5), function () {
            return self::query()->get()
                ->map(fn (self $c) => [
                    'id' => $c->id,
                    'slot' => $c->slot->value,
                    'key' => $c->key,
                    'name' => $c->name,
                    'rarity' => $c->rarity,
                    'source' => $c->source,
                    'season_id' => $c->season_id,
                    'tier' => $c->tier,
                ])
                ->keyBy('id')
                ->all();
        });
    }

    public static function forgetCatalog(): void
    {
        Cache::forget(self::CATALOG_CACHE_KEY);
    }
}
