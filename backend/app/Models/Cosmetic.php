<?php

namespace App\Models;

use App\Enums\CosmeticSlot;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class Cosmetic extends Model
{
    public const CATALOG_CACHE_KEY = 'cosmetics:catalog';

    protected $fillable = ['slot', 'key', 'name', 'rarity', 'image_path', 'source', 'season_id', 'tier'];

    protected function casts(): array
    {
        return [
            'slot' => CosmeticSlot::class,
            'tier' => 'integer',
        ];
    }

    /**
     * Host-relative URL to the uploaded image, or null when the cosmetic
     * renders from the frontend SVG registry by `key`. Same origin-relative
     * shape as User::avatarUrl so it works behind a tunnel.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->image_path
                ? parse_url(Storage::disk('public')->url($this->image_path), PHP_URL_PATH)
                : null,
        );
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
     * @return array<int, array{id:int, slot:string, key:string, name:string, rarity:string, image_url:string|null, source:string, season_id:int|null, tier:int|null}>
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
                    'image_url' => $c->image_url,
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
