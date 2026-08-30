<?php

namespace App\Models;

use App\Enums\DatasetType;
use App\Enums\DatasetVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-created content set for one game mode. `type` decides which child
 * collection is meaningful: ddf -> questions(), songle -> tracks(). Selected
 * by id when starting a game (see DdfGameController / GameRoomController); the
 * games fall back to their built-in content when no dataset is chosen.
 */
class Dataset extends Model
{
    protected $fillable = ['owner_id', 'name', 'type', 'visibility', 'language'];

    protected function casts(): array
    {
        return [
            'type' => DatasetType::class,
            'visibility' => DatasetVisibility::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Custom questions scoped to this dataset (ddf type). */
    public function questions(): HasMany
    {
        return $this->hasMany(DdfQuestion::class)->orderBy('position');
    }

    /** Imported Spotify tracks (songle type). */
    public function tracks(): HasMany
    {
        return $this->hasMany(DatasetTrack::class)->orderBy('position');
    }

    /** Owned by, or public to, the given user. */
    public function scopeUsableBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('owner_id', $user->id)
            ->orWhere('visibility', DatasetVisibility::Public->value));
    }

    public function itemCount(): int
    {
        return $this->type === DatasetType::Ddf
            ? $this->questions()->count()
            : $this->tracks()->count();
    }
}
