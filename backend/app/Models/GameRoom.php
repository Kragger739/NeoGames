<?php

namespace App\Models;

use App\Enums\DifficultyTier;
use App\Enums\GameMode;
use App\Enums\RoomStatus;
use App\Enums\SongGenre;
use Database\Factories\GameRoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'genre',
        'year_from',
        'year_to',
        'artist_name',
        'songs_per_tier',
        'guess_timeout_seconds',
        'current_tier',
        'current_song_index',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'mode' => GameMode::class,
            'genre' => SongGenre::class,
            'current_tier' => DifficultyTier::class,
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
     * and Solo never set is_eliminated, so this is just every player there.
     */
    public function activePlayers(): HasMany
    {
        return $this->players()->where('is_eliminated', false);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class, 'room_id');
    }
}
