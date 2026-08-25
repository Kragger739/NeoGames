<?php

namespace App\Models;

use Database\Factories\RoomPlayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as AuthenticatableModel;
use Illuminate\Support\Str;

/**
 * Extends the Authenticatable base model (not App\Models\User) so instances
 * can be resolved by the custom "player" guard, which is entirely separate
 * from Sanctum/host auth.
 */
class RoomPlayer extends AuthenticatableModel
{
    /** @use HasFactory<RoomPlayerFactory> */
    use HasFactory;

    protected $fillable = [
        'room_id',
        'user_id',
        'nickname',
        'connection_token',
        'score',
        'is_eliminated',
    ];

    protected $hidden = [
        'connection_token',
    ];

    protected function casts(): array
    {
        return [
            'is_eliminated' => 'boolean',
        ];
    }

    public static function generateConnectionToken(): string
    {
        return Str::random(64);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(GameRoom::class, 'room_id');
    }

    /**
     * Null for anonymous nickname-only players - only set when this seat
     * was created by (or claimed by) a logged-in account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guesses(): HasMany
    {
        return $this->hasMany(Guess::class, 'player_id');
    }
}
