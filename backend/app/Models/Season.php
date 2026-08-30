<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    protected $fillable = ['name', 'slug', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * The single season whose window contains now(), or null between seasons.
     */
    public static function current(): ?self
    {
        return static::query()
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->orderByDesc('starts_at')
            ->first();
    }

    public function cosmetics(): HasMany
    {
        return $this->hasMany(Cosmetic::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(SeasonProgress::class);
    }
}
