<?php

namespace App\Models;

use App\Enums\DifficultyTier;
use App\Enums\GameMode;
use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Enums\SongGenre;
use Database\Factories\GameRoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class GameRoom extends Model
{
    /** @use HasFactory<GameRoomFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'host_id',
        'status',
        'game',
        'mode',
        'player_mode',
        'genre',
        'year_from',
        'year_to',
        'artist_name',
        'artist_names',
        'songs_per_tier',
        'enabled_tiers',
        'guess_timeout_seconds',
        'current_tier',
        'current_song_index',
        'dataset_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'mode' => GameMode::class,
            'player_mode' => RoomPlayerMode::class,
            'genre' => SongGenre::class,
            'current_tier' => DifficultyTier::class,
            'enabled_tiers' => 'array',
            'artist_names' => 'array',
        ];
    }

    public static function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(RoomPlayer::class, 'room_id');
    }

    /**
     * Only meaningfully differs from players() in Battle Royale - Classic
     * and Custom never set is_eliminated, so this is just every player there.
     */
    public function activePlayers(): HasMany
    {
        return $this->players()->where('is_eliminated', false);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class, 'room_id');
    }

    public function ddfGame(): HasOne
    {
        return $this->hasOne(DdfGame::class);
    }

    /**
     * A custom Songle dataset (imported Spotify playlist) driving song
     * selection, or null for normal genre/year/artist selection.
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /**
     * 1-indexed position of the round currently in progress across the
     * whole game - current_song_index is 0-indexed and already reflects
     * the round in progress (RoundService sets it before dispatching the
     * round that uses it), so this is just that plus however many full
     * tiers have already been completed.
     */
    public function roundNumber(): int
    {
        $tierIndex = array_search($this->current_tier, $this->enabledTiers(), true);

        return ($tierIndex * $this->songs_per_tier) + $this->current_song_index + 1;
    }

    /** Every enabled tier plays the same songs_per_tier count. */
    public function totalRounds(): int
    {
        return $this->songs_per_tier * count($this->enabledTiers());
    }

    /**
     * This room's own subset of DifficultyTier::ordered(), always in
     * canonical Easy->Extreme order regardless of how they were stored -
     * storage order is never trusted. Null/empty (unset rooms, pre-
     * migration rows) means "every tier", preserving the pre-this-feature
     * behavior by default.
     *
     * @return array<int, DifficultyTier>
     */
    public function enabledTiers(): array
    {
        $stored = $this->enabled_tiers;

        if ($stored === null || $stored === []) {
            return DifficultyTier::ordered();
        }

        return array_values(array_filter(
            DifficultyTier::ordered(),
            fn (DifficultyTier $tier) => in_array($tier->value, $stored, true),
        ));
    }

    public function firstEnabledTier(): DifficultyTier
    {
        return $this->enabledTiers()[0];
    }

    /** Room-scoped equivalent of DifficultyTier::next() - walks this room's own subset, not the fixed global list. */
    public function nextEnabledTier(): ?DifficultyTier
    {
        $tiers = $this->enabledTiers();
        $index = array_search($this->current_tier, $tiers, true);

        return $tiers[$index + 1] ?? null;
    }
}
