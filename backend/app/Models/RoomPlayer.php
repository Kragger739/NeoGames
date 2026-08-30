<?php

namespace App\Models;

use Database\Factories\RoomPlayerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'user_id',
    ];

    /**
     * Always included in serialization - see level() / avatar() below.
     */
    protected $appends = ['level', 'avatar'];

    protected function casts(): array
    {
        return [
            'is_eliminated' => 'boolean',
        ];
    }

    /**
     * Null for an anonymous (nickname-only) seat - only a linked account
     * has a level to show. Same computed-attribute pattern as
     * User::level(); relies on the user relation already being loaded
     * (see scopeSelectForSummary()) to avoid an N+1 per player.
     */
    protected function level(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->user?->level,
        );
    }

    /**
     * The identity blob the frontend <Avatar> renders (photo + level + equipped
     * cosmetics), or null for an anonymous seat. Relies on the user relation
     * being loaded with avatar_path + equipped_cosmetics (see the scopes below).
     */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->user?->avatarPayload(),
        );
    }

    /**
     * Single source of truth for "which columns + eager loads does a
     * player-summary/scoreboard response need" - used everywhere a room's
     * players are serialized (lobby catch-up, round outcomes, game
     * finished, room reset) so `level` resolves consistently without
     * restating this select/with pair at each call site.
     */
    public function scopeSelectForSummary(Builder $query): Builder
    {
        return $query->with('user:id,xp,avatar_path,equipped_cosmetics,is_admin')
            ->select(['id', 'nickname', 'score', 'is_eliminated', 'user_id']);
    }

    /**
     * DDF's equivalent of scopeSelectForSummary() - the lobby/roster views
     * need hearts/eliminated/camera-ready, not XP level, but level is still
     * appended via the level() accessor since that stays generic.
     */
    public function scopeSelectForDdfSummary(Builder $query): Builder
    {
        return $query->with(['ddfState', 'user:id,xp,avatar_path,equipped_cosmetics,is_admin'])
            ->select(['id', 'nickname', 'user_id']);
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

    public function ddfState(): HasOne
    {
        return $this->hasOne(DdfPlayerState::class);
    }

    public function ddfAnswers(): HasMany
    {
        return $this->hasMany(DdfAnswer::class);
    }
}
