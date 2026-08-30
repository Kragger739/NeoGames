<?php

namespace App\Models;

use App\Services\LevelingService;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password', 'avatar_path', 'equipped_cosmetics', 'provider', 'provider_id'])]
#[Hidden(['password', 'remember_token', 'equipped_cosmetics'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmailTrait, Notifiable;

    /**
     * Computed, not stored - included on every serialization (register,
     * login, /api/user) without touching those controllers individually.
     */
    protected $appends = ['level', 'avatar_url', 'avatar', 'email_verified'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'xp' => 'integer',
            'equipped_cosmetics' => 'array',
        ];
    }

    /**
     * A plain boolean for the SPA - it never needs the raw timestamp, and
     * this keeps the "can this account use the app yet" check in one place.
     */
    protected function emailVerified(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->hasVerifiedEmail(),
        );
    }

    protected function level(): Attribute
    {
        return Attribute::make(
            // A freshly created-in-memory instance may not yet reflect the
            // xp column's DB-level default until it's re-fetched - default
            // to 0 rather than passing a possibly-null value along.
            get: fn () => app(LevelingService::class)->levelForXp((int) ($this->xp ?? 0)),
        );
    }

    /**
     * Host-relative URL for the uploaded profile picture, or null -
     * avatar_path itself is just a relative path on the 'public' disk (see
     * ProfileController::updateAvatar()), never exposed directly. Stripped
     * down to just the path (dropping Storage::url()'s APP_URL-based
     * scheme+host) so the browser resolves it against whatever origin
     * actually served the page - localhost, a LAN IP, or a Cloudflare
     * tunnel hostname - instead of a baked-in APP_URL that's wrong (and,
     * over an https tunnel, mixed-content-blocked) for anyone else.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatar_path
                ? parse_url(Storage::disk('public')->url($this->avatar_path), PHP_URL_PATH)
                : null,
        );
    }

    /**
     * The one identity blob the frontend <Avatar> needs, reused on every
     * surface a user appears (self, players, friends, leaderboard). `cosmetics`
     * is the equipped map resolved to render-ready {slot: {key, rarity}}.
     */
    protected function avatar(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatarPayload(),
        );
    }

    /**
     * @return array{avatar_url: string|null, level: int, cosmetics: array<string, array{key: string, rarity: string}>}
     */
    public function avatarPayload(): array
    {
        return [
            'avatar_url' => $this->avatar_url,
            'level' => $this->level,
            'cosmetics' => $this->resolveEquippedCosmetics(),
        ];
    }

    /**
     * Turns the stored { slot: cosmetic_id } map into { slot: { key, rarity } }
     * via the cached catalogue - a stale/unknown id or a slot mismatch is just
     * dropped, so a removed cosmetic never breaks rendering.
     *
     * @return array<string, array{key: string, rarity: string}>
     */
    public function resolveEquippedCosmetics(): array
    {
        $equipped = $this->equipped_cosmetics;

        if (! is_array($equipped) || $equipped === []) {
            return [];
        }

        $catalog = Cosmetic::catalog();
        $out = [];

        foreach ($equipped as $slot => $id) {
            $cosmetic = $catalog[$id] ?? null;

            if ($cosmetic !== null && $cosmetic['slot'] === $slot) {
                $out[$slot] = ['key' => $cosmetic['key'], 'rarity' => $cosmetic['rarity']];
            }
        }

        return $out;
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(GameRoom::class, 'host_id');
    }

    /** Workshop content this user has created (question sets, Songle playlists). */
    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class, 'owner_id');
    }

    public function cosmetics(): BelongsToMany
    {
        return $this->belongsToMany(Cosmetic::class, 'cosmetic_user')
            ->withPivot('source', 'acquired_at');
    }

    public function seasonProgress(): HasMany
    {
        return $this->hasMany(SeasonProgress::class);
    }

    /**
     * Songs this user (as a room's host) has already been served, across
     * every game mode - a soft, best-effort no-repeat preference, not a
     * hard cap (see RoundService::buildSelectionContext()). Cleared every
     * 80 finished games (RoundService::finishGame()).
     */
    public function songPlays(): BelongsToMany
    {
        return $this->belongsToMany(Song::class, 'user_song_plays')->withTimestamps();
    }

    /**
     * A default, editable-later handle - never left null for a real
     * account, since friend search and room auto-join both key off it.
     */
    public static function generateUniqueUsernameFrom(string $name): string
    {
        $base = Str::of($name)->slug('')->limit(20, '')->lower()->toString();
        $base = $base !== '' ? $base : 'user';

        $username = $base;
        $suffix = 1;

        while (self::where('username', $username)->exists()) {
            $suffix++;
            $username = "{$base}{$suffix}";
        }

        return $username;
    }
}
